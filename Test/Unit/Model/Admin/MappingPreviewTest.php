<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Admin;

use Iranimij\OpenImport\Model\Admin\FileSample;
use Iranimij\OpenImport\Model\Admin\MappingPreview;
use Iranimij\OpenImport\Model\Entity\EntityRegistry;
use Iranimij\OpenImport\Model\Filter\RowFilterCompiler;
use Iranimij\OpenImport\Model\Mapping\MappingCompiler;
use Iranimij\OpenImport\Model\Pipeline\ProfileCompiler;
use Iranimij\OpenImport\Model\Reader\ReaderFactory;
use Iranimij\OpenImport\Model\Transform\TransformPool;
use Iranimij\OpenImport\Model\Value\FormulaGuard;
use Iranimij\OpenImport\Test\Unit\Model\ProfileStub;
use PHPUnit\Framework\TestCase;

class MappingPreviewTest extends TestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/openimport-preview-' . bin2hex(random_bytes(4)) . '.csv';
        $rows = "sku;name;name_de;price;status\n";
        for ($i = 1; $i <= 30; $i++) {
            $rows .= "A-$i;Name $i;" . ($i === 2 ? 'Zwei' : '') . ';' . ($i === 3 ? 'abc' : "$i,5") . ';' . ($i === 4 ? 'off' : 'on') . "\n";
        }
        file_put_contents($this->file, $rows);
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    private function preview(): MappingPreview
    {
        return new MappingPreview(
            new ReaderFactory(),
            new ProfileCompiler(new MappingCompiler(new TransformPool()), new RowFilterCompiler(), new EntityRegistry())
        );
    }

    public function testFirstRowsAsMagentoReceivesThem(): void
    {
        $profile = new ProfileStub([
            'entity' => 'catalog_product',
            'format' => 'auto',
            'row_filters' => ['mode' => 'exclude', 'conditions' => [['column' => 'status', 'operator' => 'equals', 'value' => 'off']]],
            'mapping' => [
                ['target' => 'sku', 'source' => 'sku'],
                ['target' => 'store:de:name', 'source' => 'name_de'],
                ['target' => 'price', 'source' => 'price', 'transforms' => ['decimal_comma']],
            ],
        ]);

        $result = $this->preview()->preview($profile, $this->file, 'feed.csv');

        self::assertSame([], $result['problems']);
        self::assertSame(['sku', 'price', 'store_view_code', 'name'], $result['columns']);
        self::assertSame([2, 3, 3, 4, 6, 7], array_column($result['rows'], 'row'));
        self::assertSame('de', $result['rows'][2]['store']);
        self::assertSame(['sku' => 'A-1', 'price' => '1.5'], $result['rows'][0]['values']);
        self::assertSame([], $result['rows'][3]['values']);
        self::assertStringContainsString('"abc" is not a number', $result['rows'][3]['errors'][0]);
        self::assertSame(['sku' => 'A-5', 'price' => '5.5'], $result['rows'][4]['values']);
    }

    public function testProblemsInsteadOfRows(): void
    {
        $profile = new ProfileStub(['entity' => 'catalog_product', 'mapping' => [['target' => 'sku', 'source' => 'Nope']]]);

        $result = $this->preview()->preview($profile, $this->file, 'feed.csv');

        self::assertSame([], $result['rows']);
        self::assertSame([
            'The mapping does not fit this file:',
            'sku: the file has no column "Nope"',
            'The file\'s columns are: "sku", "name", "name_de", "price", "status"',
        ], $result['problems']);
        self::assertSame(['The profile has no mapping yet.'], $this->preview()->preview(new ProfileStub(), $this->file, 'x.csv')['problems']);
    }

    public function testFileSample(): void
    {
        $sample = (new FileSample(new ReaderFactory()))->read($this->file, 'feed.csv', 'auto', ['delimiter' => ';'], 3);

        self::assertSame('csv', $sample['format']);
        self::assertSame(['sku', 'name', 'name_de', 'price', 'status'], $sample['columns']);
        self::assertCount(3, $sample['rows']);
        self::assertSame('2', $sample['rows'][0]['__row']);
        self::assertSame([], $sample['sheets']);
    }

    public function testFormulaGuard(): void
    {
        $guard = new FormulaGuard();

        self::assertSame("'=SUM(A1)", $guard->protect('=SUM(A1)'));
        self::assertSame("'+49 30 1234", $guard->protect('+49 30 1234'));
        self::assertSame("'@cmd", $guard->protect('@cmd'));
        self::assertSame("'\tx", $guard->protect("\tx"));
        self::assertSame('-5', $guard->protect('-5'));
        self::assertSame('-1.5', $guard->protect('-1.5'));
        self::assertSame("'-1+2", $guard->protect('-1+2'));
        self::assertSame('plain', $guard->protect('plain'));
        self::assertSame('', $guard->protect(''));
    }
}
