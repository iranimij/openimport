<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Export;

use Iranimij\OpenImport\Model\Export\ExportMapping;
use Iranimij\OpenImport\Model\Mapping\MappingCompiler;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Transform\TransformPool;
use Iranimij\OpenImport\Test\Unit\Model\ProfileStub;
use PHPUnit\Framework\TestCase;

class ExportMappingTest extends TestCase
{
    private function mapping(): ExportMapping
    {
        return new ExportMapping(new MappingCompiler(new TransformPool()), new TransformPool());
    }

    public function testWithoutAMappingEveryColumnPassesThrough(): void
    {
        $mapping = $this->mapping()->compile(new ProfileStub(['direction' => 'export', 'mapping' => []]));

        self::assertTrue($mapping->passThrough());
        self::assertSame(['sku', 'name'], $mapping->columns(['sku', 'name']));
        self::assertSame(['sku' => 'A', 'name' => 'B'], $mapping->map(['sku' => 'A', 'name' => 'B']));
    }

    public function testMappedColumnsWithTransformsStaticsAndValueMaps(): void
    {
        $profile = new ProfileStub([
            'direction' => 'export',
            'mapping' => [
                ['target' => 'Artikelnummer', 'source' => 'sku', 'transforms' => ['trim', 'upper']],
                ['target' => 'Preis', 'source' => 'price', 'transforms' => [['name' => 'number', 'args' => ['2', ',']]]],
                ['target' => 'Lieferant', 'source' => null, 'static' => 'Shop'],
                ['target' => 'Aktiv', 'source' => 'product_online', 'transforms' => ['map']],
            ],
            'value_maps' => ['Aktiv' => ['1' => 'ja', '2' => 'nein']],
        ]);
        $mapping = $this->mapping()->compile($profile);

        self::assertFalse($mapping->passThrough());
        self::assertSame(['Artikelnummer', 'Preis', 'Lieferant', 'Aktiv'], $mapping->columns(['sku', 'price']));
        self::assertSame(
            ['Artikelnummer' => 'A-1', 'Preis' => '12,50', 'Lieferant' => 'Shop', 'Aktiv' => 'ja'],
            $mapping->map(['sku' => ' a-1 ', 'price' => '12.5', 'product_online' => '1', 'name' => 'ignored'])
        );
    }

    public function testProblemsAreListed(): void
    {
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage("The mapping has problems:\nrow 1: give the column a name.\nSKU: this column is in the file twice.\nx: Step 1: unknown transform \"shout\"");

        $this->mapping()->compile(new ProfileStub(['mapping' => [
            ['target' => '', 'source' => 'a'],
            ['target' => 'sku', 'source' => 'sku'],
            ['target' => 'SKU', 'source' => 'sku'],
            ['target' => 'x', 'source' => 'a', 'transforms' => ['shout']],
        ]]));
    }
}
