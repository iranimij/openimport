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
 * Readers take that apostrophe off again (unprotect), so an exported file imports back unchanged.
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

    /**
     * Removes the apostrophe protect() adds: "'=A1" → "=A1", "'+49 69 1234" → "+49 69 1234". Other apostrophes stay.
     *
     * @param string $value
     * @return string
     */
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function unprotect(string $value): string
    {
        if (strlen($value) < 2 || $value[0] !== "'" || !in_array($value[1], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return $value;
        }

        return substr($value, 1);
    }
}
