<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Execution\Source;

use Iranimij\OpenImport\Model\Execution\Source\FileSelector;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use PHPUnit\Framework\TestCase;

class FileSelectorTest extends TestCase
{
    /** @var array<string, int|null> */
    private const FILES = [
        'stock-2026-10-01.csv' => 100,
        'stock-2026-10-02.csv' => 300,
        'Stock-2026-10-03.CSV' => 200,
        'prices.xlsx' => 400,
        'readme.txt' => null,
        'stock-10.csv' => 300,
    ];

    public function testPatternsAreCaseInsensitiveGlobs(): void
    {
        $selector = new FileSelector();

        self::assertSame(
            ['stock-2026-10-01.csv', 'stock-2026-10-02.csv', 'Stock-2026-10-03.CSV'],
            array_keys($selector->matching(self::FILES, 'stock-????-*.csv'))
        );
        self::assertSame(['prices.xlsx', 'readme.txt'], array_keys($selector->matching(self::FILES, '*.xlsx, *.txt')));
        self::assertCount(6, $selector->matching(self::FILES, ''));
        self::assertSame([], $selector->matching(self::FILES, 'stock.csv'));
    }

    public function testPicks(): void
    {
        $selector = new FileSelector();

        self::assertSame('stock-2026-10-02.csv', $selector->pick(self::FILES, 'stock-*.csv', FileSelector::PICK_NEWEST), 'newest; same time → last name');
        self::assertSame('stock-2026-10-01.csv', $selector->pick(self::FILES, 'stock-*.csv', FileSelector::PICK_OLDEST));
        self::assertSame('Stock-2026-10-03.CSV', $selector->pick(self::FILES, 'stock-2026*', FileSelector::PICK_LAST_NAME));
        self::assertSame('stock-2026-10-01.csv', $selector->pick(self::FILES, 'stock-2026*', FileSelector::PICK_FIRST_NAME));
        self::assertSame('stock-10.csv', $selector->pick(self::FILES, 'stock-*', FileSelector::PICK_FIRST_NAME), 'natural order: 10 before 2026');
    }

    public function testNoMatchIsAPlainMessage(): void
    {
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage('No file matches "orders-*.csv" (6 file(s) in the folder).');

        (new FileSelector())->pick(self::FILES, 'orders-*.csv', FileSelector::PICK_NEWEST);
    }
}
