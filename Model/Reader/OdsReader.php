<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Reader;

use Iranimij\OpenImport\Model\Value\NumberText;

/**
 * OpenDocument spreadsheets (.ods, LibreOffice Calc), streamed from content.xml with XMLReader (ext-zip and
 * ext-xmlreader, both required by Magento).
 *
 * - The first table (sheet) or the one named in the options, by name or position.
 * - Repeated cells and rows (table:number-columns-repeated, table:number-rows-repeated) are expanded, but runs of
 *   empty repeated cells at the end of a row and empty repeated rows are dropped (Calc writes 1,000-fold repeats
 *   for the unused area).
 * - Typed values: floats and percentages from office:value, dates and times from office:date-value /
 *   office:time-value (as ISO text), booleans as 1/0, everything else as the cell's text (paragraphs joined by
 *   a line break, text:s spaces and text:tab expanded).
 * - Same safety as the Excel reader: no DOCTYPE, no network, 512 MB unpacked limit.
 */
class OdsReader implements ReaderInterface
{
    private const MAX_UNPACKED = 536870912;
    private const MAX_REPEAT = 100000;
    private const NS_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
    private const NS_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
    private const NS_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

    /**
     * @var string[]|null
     */
    private ?array $columns = null;

    private string $sheetName = '';

    /**
     * @var string[]|null
     */
    private ?array $sheets = null;

    private ColumnNames $names;

    private NumberText $numbers;

    /**
     * @param string $file absolute path
     * @param ReaderOptions $options
     */
    public function __construct(
        private readonly string $file,
        private readonly ReaderOptions $options = new ReaderOptions()
    ) {
        $info = new \SplFileInfo($file);
        if (!$info->isFile() || !$info->isReadable()) {
            throw new ReaderException(sprintf('The file "%s" cannot be read.', $info->getFilename()));
        }
        $this->names = new ColumnNames();
        $this->numbers = new NumberText();
    }

    /**
     * @inheritDoc
     */
    public function columns(): array
    {
        if ($this->columns === null) {
            foreach ($this->sheetRows() as $cells) {
                $this->columns = $this->options->hasHeader
                    ? $this->names->normalize($cells)
                    : $this->names->generated(count($cells));
                break;
            }
            $this->columns ??= [];
        }

        return $this->columns;
    }

    /**
     * @inheritDoc
     */
    public function rows(): \Generator
    {
        $columns = null;
        foreach ($this->sheetRows() as $number => $cells) {
            if ($columns === null) {
                $columns = $this->options->hasHeader
                    ? $this->names->normalize($cells)
                    : $this->names->generated(count($cells));
                $this->columns = $columns;
                if ($this->options->hasHeader) {
                    continue;
                }
            }
            $width = count($columns);
            yield $number => $this->names->combine($columns, array_pad(array_slice($cells, 0, $width), $width, ''));
        }
    }

    /**
     * @inheritDoc
     */
    public function detected(): array
    {
        $this->sheetName();

        return ['sheet' => $this->sheetName];
    }

    /**
     * Names of all sheets in document order (for the profile form).
     *
     * @return string[]
     */
    public function sheetNames(): array
    {
        if ($this->sheets !== null) {
            return $this->sheets;
        }
        $this->checkArchive();
        $reader = $this->open();
        $this->sheets = [];
        try {
            $more = $reader->read();
            while ($more) {
                if ($reader->nodeType === \XMLReader::DOC_TYPE) {
                    throw new ReaderException('The spreadsheet contains a DOCTYPE and is refused.');
                }
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'table'
                    && $reader->namespaceURI === self::NS_TABLE) {
                    $this->sheets[] = (string) $reader->getAttributeNs('name', self::NS_TABLE);
                    $more = $reader->next();
                    continue;
                }
                $more = $reader->read();
            }
        } finally {
            $reader->close();
        }
        if ($this->sheets === []) {
            throw new ReaderException('The spreadsheet has no sheets.');
        }

        return $this->sheets;
    }

    /**
     * The sheet to read, by the option (name or position) or the first one.
     */
    private function sheetName(): string
    {
        if ($this->sheetName !== '') {
            return $this->sheetName;
        }
        $names = $this->sheetNames();
        $wanted = trim($this->options->sheet);
        if ($wanted === '') {
            $name = $names[0];
        } elseif (ctype_digit($wanted)) {
            $name = $names[(int) $wanted - 1] ?? null;
        } else {
            $name = null;
            foreach ($names as $candidate) {
                if (mb_strtolower($candidate) === mb_strtolower($wanted)) {
                    $name = $candidate;
                }
            }
        }
        if ($name === null) {
            throw new ReaderException(
                sprintf('The sheet "%s" does not exist. Sheets: %s.', $wanted, implode(', ', $names))
            );
        }

        return $this->sheetName = $name;
    }

    /**
     * Rows of the chosen sheet as lists of cell texts (trailing empty cells dropped), keyed by row number.
     *
     * @return \Generator<int, string[]>
     */
    private function sheetRows(): \Generator
    {
        $sheet = $this->sheetName();
        $reader = $this->open();
        $rowNumber = 0;
        try {
            $more = $reader->read();
            while ($more) {
                if ($reader->nodeType === \XMLReader::DOC_TYPE) {
                    throw new ReaderException('The spreadsheet contains a DOCTYPE and is refused.');
                }
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->namespaceURI !== self::NS_TABLE) {
                    $more = $reader->read();
                    continue;
                }
                if ($reader->localName === 'table'
                    && (string) $reader->getAttributeNs('name', self::NS_TABLE) !== $sheet) {
                    $more = $reader->next();
                    continue;
                }
                if ($reader->localName !== 'table-row') {
                    $more = $reader->read();
                    continue;
                }
                $repeat = $this->repeat($reader->getAttributeNs('number-rows-repeated', self::NS_TABLE));
                $cells = $reader->isEmptyElement ? [] : $this->rowCells($reader);
                if ($cells === []) {
                    // Empty rows count, so row numbers match the sheet, but are not delivered.
                    $rowNumber += $repeat;
                } else {
                    for ($i = 0; $i < min($repeat, self::MAX_REPEAT); $i++) {
                        yield ++$rowNumber => $cells;
                    }
                }
                $more = $reader->read();
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * The cells of the row the reader stands on, repeats expanded, trailing empty cells dropped.
     *
     * @param \XMLReader $reader
     * @return string[]
     */
    private function rowCells(\XMLReader $reader): array
    {
        $cells = [];
        $depth = $reader->depth;
        while ($reader->read() && $reader->depth > $depth) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->namespaceURI !== self::NS_TABLE
                || !in_array($reader->localName, ['table-cell', 'covered-table-cell'], true)) {
                continue;
            }
            $repeat = $this->repeat($reader->getAttributeNs('number-columns-repeated', self::NS_TABLE));
            $value = $this->cellValue($reader);
            if ($value === '') {
                // Empty cells are filled in only when a later cell has a value (see below).
                $cells[] = ['', $repeat];
                continue;
            }
            for ($i = 0; $i < min($repeat, self::MAX_REPEAT); $i++) {
                $cells[] = [$value, 1];
            }
        }
        // Expand: empty runs before a value become that many empty cells; empty runs at the end are dropped.
        $list = [];
        $pending = 0;
        foreach ($cells as [$value, $repeat]) {
            if ($value === '') {
                $pending += $repeat;
                continue;
            }
            for ($i = 0; $i < min($pending, self::MAX_REPEAT); $i++) {
                $list[] = '';
            }
            $pending = 0;
            $list[] = $value;
        }

        return $list;
    }

    /**
     * The value of the cell the reader stands on: typed office:*-value attributes first, else its text.
     */
    private function cellValue(\XMLReader $reader): string
    {
        $type = (string) $reader->getAttributeNs('value-type', self::NS_OFFICE);
        $empty = $reader->isEmptyElement;
        $typed = match ($type) {
            'float', 'currency', 'percentage'
                => $this->number((string) $reader->getAttributeNs('value', self::NS_OFFICE)),
            'date' => (string) $reader->getAttributeNs('date-value', self::NS_OFFICE),
            'time' => $this->time((string) $reader->getAttributeNs('time-value', self::NS_OFFICE)),
            'boolean' => (string) $reader->getAttributeNs('boolean-value', self::NS_OFFICE) === 'true' ? '1' : '0',
            default => null,
        };
        $text = $empty ? '' : $this->cellText($reader);
        if ($typed !== null && $typed !== '') {
            return $type === 'date' ? str_replace('T', ' ', $typed) : $typed;
        }

        return $text;
    }

    /**
     * The paragraphs of a cell, joined by line breaks; text:s and text:tab expanded, annotations left out.
     */
    private function cellText(\XMLReader $reader): string
    {
        $paragraphs = [];
        $text = '';
        $depth = $reader->depth;
        while ($reader->read() && $reader->depth > $depth) {
            $isText = $reader->namespaceURI === self::NS_TEXT;
            if ($reader->nodeType === \XMLReader::ELEMENT) {
                if ($reader->namespaceURI === self::NS_OFFICE && $reader->localName === 'annotation') {
                    if (!$reader->isEmptyElement) {
                        $reader->next();
                    }
                    continue;
                }
                if ($isText && $reader->localName === 's') {
                    $text .= str_repeat(' ', max(1, (int) ($reader->getAttributeNs('c', self::NS_TEXT) ?? 1)));
                } elseif ($isText && $reader->localName === 'tab') {
                    $text .= "\t";
                } elseif ($isText && $reader->localName === 'line-break') {
                    $text .= "\n";
                } elseif ($isText && $reader->localName === 'p' && $reader->isEmptyElement) {
                    $paragraphs[] = '';
                }
            } elseif ($reader->nodeType === \XMLReader::END_ELEMENT && $isText && $reader->localName === 'p') {
                $paragraphs[] = $text;
                $text = '';
            } elseif ($reader->nodeType === \XMLReader::TEXT || $reader->nodeType === \XMLReader::SIGNIFICANT_WHITESPACE
                || $reader->nodeType === \XMLReader::CDATA) {
                $text .= $reader->value;
            }
        }
        if ($text !== '') {
            $paragraphs[] = $text;
        }

        return implode("\n", $paragraphs);
    }

    private function number(string $value): string
    {
        if (!is_numeric($value)) {
            return $value;
        }

        return preg_match('/^-?\d+$/', $value) === 1 ? $value : $this->numbers->fromFloat((float) $value);
    }

    /**
     * "PT14H30M00S" => "14:30:00".
     */
    private function time(string $value): string
    {
        if (preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?$/', $value, $m) !== 1) {
            return $value;
        }

        return sprintf('%02d:%02d:%02d', (int) ($m[1] ?? 0), (int) ($m[2] ?? 0), (int) floor((float) ($m[3] ?? 0)));
    }

    private function repeat(?string $attribute): int
    {
        return max(1, (int) ($attribute ?? 1));
    }

    private function open(): \XMLReader
    {
        $reader = new \XMLReader();
        if (!$reader->open('zip://' . $this->file . '#content.xml', null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new ReaderException('The file is not an OpenDocument spreadsheet (.ods).');
        }

        return $reader;
    }

    private function checkArchive(): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($this->file, \ZipArchive::RDONLY) !== true || $zip->locateName('content.xml') === false) {
            throw new ReaderException('The file is not an OpenDocument spreadsheet (.ods).');
        }
        $mime = $zip->getFromName('mimetype');
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $total += (int) ($stat['size'] ?? 0);
        }
        $zip->close();
        if ($mime !== false && trim($mime) !== '' && trim($mime) !== 'application/vnd.oasis.opendocument.spreadsheet') {
            throw new ReaderException(sprintf('The file is an OpenDocument %s, not a spreadsheet.', trim($mime)));
        }
        if ($total > self::MAX_UNPACKED) {
            throw new ReaderException('The spreadsheet would unpack to more than 512 MB and is refused.');
        }
    }
}
