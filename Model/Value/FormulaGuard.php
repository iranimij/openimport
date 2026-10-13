<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Value;

/**
 * Keeps spreadsheets from running cell values as formulas (CSV/formula injection): a value that starts with =, +, -,
 * @, a tab or a carriage return gets a leading apostrophe. Plain negative numbers ("-5", "-1.5") stay as they are.
 */
class FormulaGuard
{
    /**
     * @param string $value
     * @return string
     */
    public function protect(string $value): string
    {
        if ($value === '' || !in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return $value;
        }
        if (preg_match('/^-\d+(\.\d+)?$/', $value) === 1) {
            return $value;
        }

        return "'" . $value;
    }
}
