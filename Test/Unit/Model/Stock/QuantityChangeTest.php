<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Stock;

use Iranimij\OpenImport\Model\Stock\QuantityChange;
use PHPUnit\Framework\TestCase;

class QuantityChangeTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: float|null}>
     */
    public static function values(): array
    {
        return [
            'plus' => ['+5', 5.0],
            'minus' => ['-3', -3.0],
            'plain' => ['4', 4.0],
            'decimal comma' => ['+2,5', 2.5],
            'decimal point' => ['-0.25', -0.25],
            'spaces' => [' + 7 ', 7.0],
            'minus sign' => ["\u{2212}2", -2.0],
            'zero' => ['0', 0.0],
            'text' => ['five', null],
            'two signs' => ['+-1', null],
            'thousands' => ['1.000,5', null],
        ];
    }

    /**
     * @param string $value
     * @param float|null $change
     * @dataProvider values
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('values')]
    public function testParse(string $value, ?float $change): void
    {
        self::assertSame($change, (new QuantityChange())->parse($value));
    }

    public function testFormat(): void
    {
        $change = new QuantityChange();
        self::assertSame(['12', '2.5', '-1', '0', '0.3333'], [
            $change->format(12.0), $change->format(2.5), $change->format(-1.0), $change->format(-0.0),
            $change->format(1 / 3),
        ]);
    }
}
