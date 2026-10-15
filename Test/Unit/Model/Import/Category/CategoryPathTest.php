<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Import\Category;

use Iranimij\OpenImport\Model\Import\Category\CategoryPath;
use PHPUnit\Framework\TestCase;

class CategoryPathTest extends TestCase
{
    public function testSegmentsAndJoin(): void
    {
        $paths = new CategoryPath();
        self::assertSame(['Default Category', 'Men', 'Shoes'], $paths->segments('Default Category/Men/Shoes'));
        self::assertSame(['Default Category', 'Men', 'Shoes'], $paths->segments(' Default Category / Men / Shoes '));
        self::assertSame(['Default Category', 'Sale 50/50'], $paths->segments('Default Category/Sale 50\/50'));
        self::assertSame([], $paths->segments(' / '));
        self::assertSame('Default Category/Sale 50\/50', $paths->join(['Default Category', 'Sale 50/50']));
        self::assertSame('default category/men', $paths->key('Default Category / MEN/'));
    }
}
