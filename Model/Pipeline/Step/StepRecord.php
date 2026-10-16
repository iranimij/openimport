<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Pipeline\Step;

/**
 * One mapped record on its way to the spool, as row steps see it: its core rows (default row first, then one per
 * store view) may be changed; a step that cannot handle the record fails it, and the record becomes a row error.
 */
class StepRecord
{
    private string $error = '';

    private string $errorColumn = '';

    private bool $incomplete = false;

    /**
     * @param int $line source row number
     * @param string $identifier
     * @param array<int, array<string, string>> $rows core rows, changeable by steps
     */
    public function __construct(
        public readonly int $line,
        public readonly string $identifier,
        public array $rows
    ) {
    }

    /**
     * Marks the record as not importable; the first reason wins.
     *
     * @param string $message
     * @param string $column the core column the problem is in
     * @return void
     */
    public function fail(string $message, string $column = ''): void
    {
        if ($this->error === '') {
            $this->error = $message;
            $this->errorColumn = $column;
        }
    }

    /**
     * The record goes to core without something it asked for (a picture left out): "skip unchanged rows" must not
     * remember it as done, so the next run tries again.
     *
     * @return void
     */
    public function markIncomplete(): void
    {
        $this->incomplete = true;
    }

    public function isIncomplete(): bool
    {
        return $this->incomplete;
    }

    public function isFailed(): bool
    {
        return $this->error !== '';
    }

    public function error(): string
    {
        return $this->error;
    }

    public function errorColumn(): string
    {
        return $this->errorColumn;
    }
}
