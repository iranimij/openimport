<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Reader;

use Iranimij\OpenImport\Model\Value\FormulaGuard;

use Iranimij\OpenImport\Model\Value\NumberText;

/**
 * Flattens one structured record (JSON object, XML element) into columns:
 * nested objects become "parent.child", lists of plain values are joined with a comma, lists of objects are kept as
 * JSON text in one column, booleans become 1 / 0 and null becomes ''.
 */
class Flattener
{
    private NumberText $numbers;

    public function __construct()
    {
        $this->numbers = new NumberText();
    }

    /**
     * @param array<string|int, mixed> $record
     * @param string $prefix
     * @return array<string, string>
     */
    public function flatten(array $record, string $prefix = ''): array
    {
        $row = [];
        foreach ($record as $key => $value) {
            // Key '' is an element's own text when it also has attributes (XML): it takes the parent's name.
            $name = match (true) {
                (string) $key === '' => $prefix === '' ? 'value' : $prefix,
                $prefix === '' => (string) $key,
                default => $prefix . '.' . $key,
            };
            if (is_array($value)) {
                if (array_is_list($value)) {
                    $row[$name] = $this->isScalarList($value)
                        ? implode(',', array_map([$this, 'scalar'], $value))
                        : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } else {
                    $row += $this->flatten($value, $name);
                }
                continue;
            }
            $row[$name] = $this->scalar($value);
        }

        return $row;
    }

    /**
     * @param array<int, mixed> $values
     * @return bool
     */
    private function isScalarList(array $values): bool
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A plain value as text.
     *
     * @param mixed $value
     * @return string
     */
    public function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_float($value) => $this->numbers->fromFloat($value),
            default => FormulaGuard::unprotect((string) $value),
        };
    }
}
