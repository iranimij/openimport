<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Writer;

use Iranimij\OpenImport\Model\Value\FormulaGuard;
use Iranimij\OpenImport\Model\Value\NumberText;

/**
 * Turns row values into text cells the same way for every writer: null → '', booleans → 1/0, floats without
 * exponent, and (when switched on) spreadsheet formulas neutralised.
 */
class Cells
{
    /**
     * @param FormulaGuard $guard
     * @param NumberText $numbers
     */
    public function __construct(
        private readonly FormulaGuard $guard,
        private readonly NumberText $numbers
    ) {
    }

    /**
     * @param string[] $columns
     * @param array<string, mixed> $row
     * @param bool $guardFormulas
     * @return string[] cells in column order
     */
    public function list(array $columns, array $row, bool $guardFormulas): array
    {
        $cells = [];
        foreach ($columns as $column) {
            $cells[] = $this->text($row[$column] ?? null, $guardFormulas);
        }

        return $cells;
    }

    /**
     * @param mixed $value
     * @param bool $guardFormulas
     * @return string
     */
    public function text(mixed $value, bool $guardFormulas): string
    {
        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_float($value) => $this->numbers->fromFloat($value),
            is_scalar($value), $value instanceof \Stringable => (string) $value,
            is_array($value) => implode(',', array_map(fn ($item): string => $this->text($item, false), $value)),
            default => '',
        };

        return $guardFormulas ? $this->guard->protect($text) : $text;
    }
}
