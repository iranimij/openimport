<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Writer;

/**
 * File handling shared by the writers: open for writing, append text, close once.
 */
abstract class AbstractWriter implements WriterInterface
{
    /**
     * @var string[]
     */
    protected array $columns = [];

    /**
     * @var array<string, mixed>
     */
    protected array $options = [];

    protected int $rows = 0;

    private ?\SplFileObject $file = null;

    private string $path = '';

    /**
     * @param Cells $cells
     */
    public function __construct(
        protected readonly Cells $cells
    ) {
    }

    /**
     * @inheritDoc
     */
    public function open(string $path, array $columns, array $options = []): void
    {
        if ($columns === []) {
            throw new WriterException('Nothing to export: the file would have no columns.');
        }
        $this->columns = array_values(array_map('strval', $columns));
        $this->options = $options;
        $this->rows = 0;
        $this->path = $path;
        try {
            $this->file = new \SplFileObject($path, 'wb');
        } catch (\RuntimeException $exception) {
            throw new WriterException(sprintf('The file %s cannot be written: %s', $path, $exception->getMessage()));
        }
        $this->start();
    }

    /**
     * @inheritDoc
     */
    public function write(array $row): void
    {
        if ($this->file === null) {
            throw new WriterException('The writer is not open.');
        }
        $this->row($row);
        $this->rows++;
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        if ($this->file === null) {
            return;
        }
        $this->finish();
        $this->file = null;
    }

    /**
     * @return int rows written so far
     */
    public function count(): int
    {
        return $this->rows;
    }

    /**
     * @return string
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * @param string $text
     * @return void
     */
    protected function append(string $text): void
    {
        if ($this->file === null || $this->file->fwrite($text) === false) {
            throw new WriterException(sprintf('The file %s cannot be written (disk full?).', $this->path));
        }
    }

    /**
     * @param string $name
     * @param string $default
     * @return string
     */
    protected function option(string $name, string $default): string
    {
        $value = $this->options[$name] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }

    /**
     * @return bool whether cells that look like formulas are neutralised (option formula_guard, default on)
     */
    protected function guardFormulas(): bool
    {
        return !isset($this->options['formula_guard']) || !empty($this->options['formula_guard']);
    }

    /**
     * Writes what comes before the first row.
     *
     * @return void
     */
    abstract protected function start(): void;

    /**
     * @param array<string, mixed> $row
     * @return void
     */
    abstract protected function row(array $row): void;

    /**
     * Writes what comes after the last row.
     *
     * @return void
     */
    abstract protected function finish(): void;
}
