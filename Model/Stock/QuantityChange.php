<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Stock;

/**
 * Reads a change of quantity ("+5", "-3", "2,5", "4") and writes the new quantity the way Magento's import wants it.
 */
class QuantityChange
{
    /**
     * @param string $value
     * @return float|null the change, null when the value is not a number
     */
    public function parse(string $value): ?float
    {
        $value = str_replace(["\u{2212}", ' '], ['-', ''], trim($value));
        if (preg_match('/^([+-]?)(\d+(?:[.,]\d+)?)$/', $value, $match) !== 1) {
            return null;
        }
        $number = (float) str_replace(',', '.', $match[2]);

        return $match[1] === '-' ? -$number : $number;
    }

    /**
     * @param float $quantity
     * @return string "12", "2.5", "-1"
     */
    public function format(float $quantity): string
    {
        $text = rtrim(rtrim(number_format(round($quantity, 4), 4, '.', ''), '0'), '.');

        return $text === '-0' || $text === '' ? '0' : $text;
    }
}
