<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Reader;

use Iranimij\OpenImport\Model\Reader\ColumnNames;
use Iranimij\OpenImport\Model\Reader\ReaderInterface;
use PHPUnit\Framework\TestCase;

class ColumnNamesTest extends TestCase
{
    public function testNamesAreTrimmedEmptyOnesNamedAndDuplicatesNumbered(): void
    {
        self::assertSame(
            ['sku', 'column_2', 'Name', 'name (2)', 'name (3)', 'price'],
            (new ColumnNames())->normalize(["\u{FEFF} sku ", '', 'Name', 'name', 'name ', 'price'])
        );
    }

    public function testADuplicateDoesNotCollideWithAnExistingNumberedName(): void
    {
        self::assertSame(['a', 'a (2)', 'a (3)'], (new ColumnNames())->normalize(['a', 'a (2)', 'a']));
    }

    public function testGeneratedNames(): void
    {
        self::assertSame(['column_1', 'column_2'], (new ColumnNames())->generated(2));
        self::assertSame([], (new ColumnNames())->generated(0));
    }

    public function testCombineFillsMissingCellsAndMarksFilledExtraOnes(): void
    {
        self::assertSame(['a' => '1', 'b' => ''], (new ColumnNames())->combine(['a', 'b'], ['1']));
        self::assertSame(['a' => '1', 'b' => ''], (new ColumnNames())->combine(['a', 'b'], [1, null, '', ' ']));
        self::assertSame(
            ['a' => '1', 'b' => '', ReaderInterface::EXTRA_CELLS => 'extra | more'],
            (new ColumnNames())->combine(['a', 'b'], [1, null, 'extra', '', 'more'])
        );
    }
}
