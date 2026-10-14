<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Writer;

/**
 * CSV: delimiter (default comma; "tab" for TSV), enclosure, line ending, optional UTF-8 BOM (Excel opens it with
 * the right encoding), optional Windows-1252 encoding. Every cell is quoted when it needs to be.
 */
class CsvWriter extends AbstractWriter
{
    /**
     * @inheritDoc
     */
    public function extension(): string
    {
        return $this->delimiter() === "\t" ? 'tsv' : 'csv';
    }

    /**
     * @inheritDoc
     */
    public function contentType(): string
    {
        return $this->delimiter() === "\t" ? 'text/tab-separated-values' : 'text/csv';
    }

    /**
     * @inheritDoc
     */
    protected function start(): void
    {
        if (!empty($this->options['bom']) && $this->encoding() === 'UTF-8') {
            $this->append("\xEF\xBB\xBF");
        }
        $this->line($this->columns);
    }

    /**
     * @inheritDoc
     */
    protected function row(array $row): void
    {
        $this->line($this->cells->list($this->columns, $row, $this->guardFormulas()));
    }

    /**
     * @inheritDoc
     */
    protected function finish(): void
    {
    }

    /**
     * @param string[] $cells
     * @return void
     */
    private function line(array $cells): void
    {
        $delimiter = $this->delimiter();
        $enclosure = $this->option('enclosure', '"');
        $out = [];
        foreach ($cells as $cell) {
            $needs = $cell === '' ? false : (
                str_contains($cell, $delimiter) || str_contains($cell, $enclosure) || str_contains($cell, "\n")
                || str_contains($cell, "\r") || $cell[0] === ' ' || $cell[-1] === ' '
            );
            $out[] = $needs ? $enclosure . str_replace($enclosure, $enclosure . $enclosure, $cell) . $enclosure : $cell;
        }
        $line = implode($delimiter, $out) . ($this->option('line_ending', 'lf') === 'crlf' ? "\r\n" : "\n");
        if ($this->encoding() !== 'UTF-8') {
            $line = (string) mb_convert_encoding($line, $this->encoding(), 'UTF-8');
        }
        $this->append($line);
    }

    /**
     * @return string
     */
    private function delimiter(): string
    {
        $delimiter = $this->option('delimiter', ',');

        return match (strtolower($delimiter)) {
            'tab', '\\t' => "\t",
            'semicolon' => ';',
            'comma', 'auto' => ',',
            'pipe' => '|',
            default => $delimiter[0],
        };
    }

    /**
     * @return string UTF-8 or Windows-1252
     */
    private function encoding(): string
    {
        return strtolower($this->option('encoding', 'utf-8')) === 'windows-1252' ? 'Windows-1252' : 'UTF-8';
    }
}
