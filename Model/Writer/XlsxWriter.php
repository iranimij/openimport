<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Writer;

use Iranimij\OpenImport\Model\Value\NumberParser;

/**
 * Excel .xlsx without a library: one worksheet (option "sheet" names it) streamed to a temporary XML file with
 * inline strings (no shared-strings table to keep in memory), zipped with the workbook parts on close. Plain
 * numbers become numeric cells (Excel sums them); everything else is text, so leading zeros and SKUs survive.
 */
class XlsxWriter extends AbstractWriter
{
    private const MAX_ROWS = 1048576;

    private const LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private ?\SplFileObject $sheet = null;

    private string $sheetPath = '';

    private int $rowNumber = 0;

    /**
     * @param Cells $cells
     * @param NumberParser $numbers
     */
    public function __construct(
        Cells $cells,
        private readonly NumberParser $numbers
    ) {
        parent::__construct($cells);
    }

    /**
     * @inheritDoc
     */
    public function extension(): string
    {
        return 'xlsx';
    }

    /**
     * @inheritDoc
     */
    public function contentType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    /**
     * @inheritDoc
     */
    protected function start(): void
    {
        $this->sheetPath = $this->path() . '.sheet.xml';
        try {
            $this->sheet = new \SplFileObject($this->sheetPath, 'wb');
        } catch (\RuntimeException $exception) {
            throw new WriterException(sprintf('The file %s cannot be written: %s', $this->sheetPath, $exception->getMessage()));
        }
        $this->rowNumber = 0;
        $this->sheetAppend(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetData>'
        );
        $this->sheetRow(array_map(static fn (string $column): array => ['s', $column], $this->columns));
    }

    /**
     * @inheritDoc
     */
    protected function row(array $row): void
    {
        $cells = [];
        foreach ($this->cells->list($this->columns, $row, $this->guardFormulas()) as $text) {
            $cells[] = $text !== '' && $this->numbers->isPlain($text) && strlen(trim($text)) <= 15
                && preg_match('/^\s*[+-]?0\d/', $text) !== 1
                ? ['n', trim($text)]
                : ['s', $text];
        }
        $this->sheetRow($cells);
    }

    /**
     * @inheritDoc
     */
    protected function finish(): void
    {
        $this->sheetAppend('</sheetData></worksheet>');
        $this->sheet = null;
        $zip = new \ZipArchive();
        $path = $this->path();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new WriterException(sprintf('The file %s cannot be written.', $path));
        }
        $sheetName = $this->sheetName();
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rels());
        $zip->addFromString('xl/workbook.xml', $this->workbook($sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFile($this->sheetPath, 'xl/worksheets/sheet1.xml');
        if (!$zip->close()) {
            throw new WriterException(sprintf('The file %s cannot be written.', $path));
        }
        $this->removeSheetFile();
    }

    /**
     * The temporary sheet XML is not needed once it is inside the zip.
     *
     * @return void
     */
    private function removeSheetFile(): void
    {
        $info = new \SplFileInfo($this->sheetPath);
        if ($info->isFile()) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction -- own temporary file next to the output
            unlink($this->sheetPath);
        }
    }

    /**
     * @param array<int, array{0: string, 1: string}> $cells type (s|n) and text
     * @return void
     */
    private function sheetRow(array $cells): void
    {
        $this->rowNumber++;
        if ($this->rowNumber > self::MAX_ROWS) {
            throw new WriterException(sprintf('Excel files hold at most %s rows; use CSV for this export.', number_format(self::MAX_ROWS - 1)));
        }
        $xml = '<row r="' . $this->rowNumber . '">';
        foreach ($cells as $index => [$type, $text]) {
            if ($text === '') {
                continue;
            }
            $reference = $this->columnName($index) . $this->rowNumber;
            $xml .= $type === 'n'
                ? '<c r="' . $reference . '"><v>' . $text . '</v></c>'
                : '<c r="' . $reference . '" t="inlineStr"><is><t' . (preg_match('/^\s|\s$/', $text) === 1 ? ' xml:space="preserve"' : '') . '>'
                    . $this->escape($this->cleanText($text)) . '</t></is></c>';
        }
        $this->sheetAppend($xml . '</row>');
    }

    /**
     * The five XML escapes, by hand: the framework's escaper is for HTML.
     *
     * @param string $text
     * @return string
     */
    private function escape(string $text): string
    {
        return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'], $text);
    }

    /**
     * Characters XML 1.0 cannot carry are dropped.
     *
     * @param string $text
     * @return string
     */
    private function cleanText(string $text): string
    {
        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);
    }

    /**
     * @param int $index 0-based
     * @return string A, B, …, Z, AA, …
     */
    private function columnName(int $index): string
    {
        $name = '';
        $index++;
        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $name = self::LETTERS[$remainder] . $name;
            $index = intdiv($index - 1, 26);
        }

        return $name;
    }

    /**
     * @param string $xml
     * @return void
     */
    private function sheetAppend(string $xml): void
    {
        if ($this->sheet === null || $this->sheet->fwrite($xml) === false) {
            throw new WriterException(sprintf('The file %s cannot be written (disk full?).', $this->sheetPath));
        }
    }

    /**
     * @return string the sheet name Excel accepts (31 characters, no []:*?/\)
     */
    private function sheetName(): string
    {
        $name = trim((string) preg_replace('/[\[\]:*?\/\\\\]+/', ' ', $this->option('sheet', 'Export')));

        return mb_substr($name === '' ? 'Export' : $name, 0, 31);
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function rels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbook(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $this->escape($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            . '</styleSheet>';
    }
}
