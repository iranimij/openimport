<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Reader;

use Iranimij\OpenImport\Model\Value\FormulaGuard;

/**
 * Turns raw header cells into usable, unique column names.
 */
class ColumnNames
{
    /**
     * Trims names, strips a byte order mark, names empty headers "column_<n>" and makes duplicates unique as
     * "name (2)", "name (3)" (1-based positions, as merchants count spreadsheet columns).
     *
     * @param array<int, mixed> $raw
     * @return string[]
     */
    public function normalize(array $raw): array
    {
        $names = [];
        $seen = [];
        foreach (array_values($raw) as $index => $value) {
            $name = trim(str_replace("\u{FEFF}", '', (string) $value));
            if ($name === '') {
                $name = 'column_' . ($index + 1);
            }
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                $seen[$key]++;
                $candidate = $name . ' (' . $seen[$key] . ')';
                while (isset($seen[mb_strtolower($candidate)])) {
                    $seen[$key]++;
                    $candidate = $name . ' (' . $seen[$key] . ')';
                }
                $seen[mb_strtolower($candidate)] = 1;
                $name = $candidate;
            } else {
                $seen[$key] = 1;
            }
            $names[] = $name;
        }

        return $names;
    }

    /**
     * Generated names for files without a header row.
     *
     * @param int $count
     * @return string[]
     */
    public function generated(int $count): array
    {
        return array_map(static fn (int $i): string => 'column_' . $i, $count > 0 ? range(1, $count) : []);
    }

    /**
     * Combines names and cells; missing cells become '', extra cells are dropped.
     *
     * @param string[] $names
     * @param array<int, mixed> $cells
     * @return array<string, string>
     */
    public function combine(array $names, array $cells): array
    {
        $row = [];
        foreach ($names as $index => $name) {
            $row[$name] = FormulaGuard::unprotect((string) ($cells[$index] ?? ''));
        }
        // Filled cells right of the last column: usually a value with an unquoted delimiter, which shifts the row.
        $extra = array_filter(
            array_slice($cells, count($names)),
            static fn ($cell): bool => $cell !== null && trim((string) $cell) !== ''
        );
        if ($extra !== []) {
            $row[ReaderInterface::EXTRA_CELLS] = implode(' | ', array_map('strval', $extra));
        }

        return $row;
    }
}
