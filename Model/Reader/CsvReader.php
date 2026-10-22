<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Reader;

/**
 * Streams a CSV (or TSV) file.
 *
 * - Delimiter: detected from the header line (, ; tab |) unless set.
 * - Encoding: UTF-8 (with or without byte order mark), UTF-16 with byte order mark (Excel "Unicode text"), or a
 *   single-byte legacy encoding (Windows-1252 when the file is not valid UTF-8); everything is returned as UTF-8.
 * - Quoted cells may contain delimiters and line breaks. Empty lines are skipped but still counted.
 * - Line breaks: LF, CRLF, and CR alone (old Mac and some ERP exports) are all read as line breaks.
 * - A line longer than MAX_LINE_BYTES stops the file with a plain error before anything is read into memory.
 */
class CsvReader implements ReaderInterface
{
    private const DELIMITERS = [',', ';', "\t", '|'];
    private const SAMPLE_BYTES = 262144;

    /**
     * Longest line read (8 MB): longer lines are not CSV (a binary file, a file without line breaks).
     */
    public const MAX_LINE_BYTES = 8388608;

    private ?bool $crOnly = null;

    private bool $lengthsChecked = false;

    /**
     * @var string[]|null
     */
    private ?array $columns = null;

    private ?string $delimiter = null;

    private ?string $encoding = null;

    private ColumnNames $names;

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
    }

    /**
     * @inheritDoc
     */
    public function columns(): array
    {
        if ($this->columns === null) {
            foreach ($this->records() as $cells) {
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
        $line = 0;
        foreach ($this->records() as $line => $cells) {
            if ($columns === null) {
                $columns = $this->options->hasHeader ? $this->names->normalize($cells) : $this->names->generated(count($cells));
                $this->columns = $columns;
                if ($this->options->hasHeader) {
                    continue;
                }
            }
            yield $line => $this->names->combine($columns, $cells);
        }
    }

    /**
     * @inheritDoc
     */
    public function detected(): array
    {
        return [
            'delimiter' => $this->delimiter() === "\t" ? '\t' : $this->delimiter(),
            'encoding' => $this->encoding(),
        ];
    }

    /**
     * Non-empty records as cell lists, keyed by their 1-based record number.
     *
     * @return \Generator<int, array<int, string>>
     */
    private function records(): \Generator
    {
        $this->checkLineLengths();
        $file = new \SplFileObject($this->streamPath(), 'r');
        $file->setFlags(\SplFileObject::READ_CSV);
        $file->setCsvControl($this->delimiter(), $this->options->enclosure, '');
        $convert = !in_array($this->encoding(), ['UTF-8', 'UTF-16LE', 'UTF-16BE'], true);
        $number = 0;
        $first = true;
        while (!$file->eof()) {
            $cells = $file->fgetcsv($this->delimiter(), $this->options->enclosure, '');
            if ($cells === false) {
                break;
            }
            $number++;
            if ($cells === [null] || $cells === [] || $this->isEmpty($cells)) {
                continue;
            }
            $cells = array_map(static fn ($cell): string => (string) $cell, $cells);
            if ($first) {
                $cells[0] = $this->stripBom($cells[0]);
                $first = false;
            }
            if ($convert) {
                $encoding = $this->encoding();
                $cells = array_map(static fn (string $cell): string => mb_convert_encoding($cell, 'UTF-8', $encoding), $cells);
            }
            yield $number => $cells;
        }
    }

    /**
     * @param array<int, mixed> $cells
     * @return bool
     */
    private function isEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function stripBom(string $cell): string
    {
        return str_starts_with($cell, "\xEF\xBB\xBF") ? substr($cell, 3) : $cell;
    }

    /**
     * The file itself, or a converting stream for UTF-16 files.
     */
    private function streamPath(): string
    {
        $filters = match ($this->encoding()) {
            'UTF-16LE' => ['convert.iconv.UTF-16LE.UTF-8'],
            'UTF-16BE' => ['convert.iconv.UTF-16BE.UTF-8'],
            default => [],
        };
        if ($this->crOnly()) {
            CrLineEndingsFilter::register();
            $filters[] = CrLineEndingsFilter::NAME;
        }

        return $filters === [] ? $this->file : 'php://filter/read=' . implode('|', $filters) . '/resource=' . $this->file;
    }

    /**
     * Whether the file breaks lines with CR alone: the sample has CRs but no LF.
     */
    private function crOnly(): bool
    {
        if ($this->crOnly === null) {
            $sample = $this->sample();
            if (str_starts_with($sample, "\xFF\xFE") || str_starts_with($sample, "\xFE\xFF")) {
                $sample = str_replace("\0", '', $sample);
            }
            $this->crOnly = str_contains($sample, "\r") && !str_contains($sample, "\n");
        }

        return $this->crOnly;
    }

    /**
     * Refuses a file with a line longer than MAX_LINE_BYTES before the CSV parser reads such a line into memory.
     *
     * @throws ReaderException
     */
    private function checkLineLengths(): void
    {
        if ($this->lengthsChecked) {
            return;
        }
        $file = new \SplFileObject($this->streamPath(), 'r');
        $file->setMaxLineLen(self::MAX_LINE_BYTES);
        $number = 1;
        while (!$file->eof()) {
            $chunk = (string) $file->fgets();
            if (strlen($chunk) >= self::MAX_LINE_BYTES && !str_ends_with($chunk, "\n") && !$file->eof()) {
                throw new ReaderException(sprintf(
                    'Line %d of the file is longer than %d MB. The file is probably not a CSV file, or its '
                    . 'line breaks are missing.',
                    $number,
                    intdiv(self::MAX_LINE_BYTES, 1048576)
                ));
            }
            if (str_ends_with($chunk, "\n")) {
                $number++;
            }
        }
        $this->lengthsChecked = true;
    }

    private function sample(): string
    {
        $file = new \SplFileObject($this->file, 'r');
        $sample = $file->fread(self::SAMPLE_BYTES);

        return $sample === false ? '' : $sample;
    }

    private function encoding(): string
    {
        if ($this->encoding !== null) {
            return $this->encoding;
        }
        $requested = strtoupper($this->options->encoding);
        if ($requested !== strtoupper(ReaderOptions::AUTO) && $requested !== '') {
            if (!in_array($requested, ['UTF-8', 'UTF-16LE', 'UTF-16BE', 'WINDOWS-1252', 'ISO-8859-1', 'ISO-8859-15'], true)) {
                throw new ReaderException(sprintf('The encoding "%s" is not supported.', $this->options->encoding));
            }
            return $this->encoding = $requested === 'WINDOWS-1252' ? 'Windows-1252' : $requested;
        }
        $sample = $this->sample();
        if (str_starts_with($sample, "\xFF\xFE")) {
            return $this->encoding = 'UTF-16LE';
        }
        if (str_starts_with($sample, "\xFE\xFF")) {
            return $this->encoding = 'UTF-16BE';
        }
        // A cut multi-byte character at the end of the sample must not make a UTF-8 file look invalid.
        $probe = strlen($sample) === self::SAMPLE_BYTES ? substr($sample, 0, -4) : $sample;

        return $this->encoding = mb_check_encoding($probe, 'UTF-8') ? 'UTF-8' : 'Windows-1252';
    }

    private function delimiter(): string
    {
        if ($this->delimiter !== null) {
            return $this->delimiter;
        }
        $requested = $this->options->delimiter;
        if ($requested !== ReaderOptions::AUTO && $requested !== '') {
            if (strlen($requested) !== 1) {
                throw new ReaderException('The delimiter must be a single character.');
            }
            return $this->delimiter = $requested;
        }

        return $this->delimiter = $this->detectDelimiter($this->firstLine());
    }

    /**
     * The first line of the file in UTF-8 (enough to count delimiters).
     */
    private function firstLine(): string
    {
        $file = new \SplFileObject($this->streamPath(), 'r');
        while (!$file->eof()) {
            $line = (string) $file->fgets();
            if (trim($line) !== '') {
                return $line;
            }
        }

        return '';
    }

    /**
     * Counts each candidate outside quotes; the most frequent wins, ties in the order , ; tab |.
     */
    private function detectDelimiter(string $line): string
    {
        $counts = array_fill_keys(self::DELIMITERS, 0);
        $quoted = false;
        $enclosure = $this->options->enclosure;
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            if ($char === $enclosure) {
                $quoted = !$quoted;
            } elseif (!$quoted && isset($counts[$char])) {
                $counts[$char]++;
            }
        }
        $best = ',';
        foreach (self::DELIMITERS as $candidate) {
            if ($counts[$candidate] > $counts[$best]) {
                $best = $candidate;
            }
        }

        return $best;
    }
}
