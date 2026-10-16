<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Configurable;

use Iranimij\OpenImport\Model\Configurable\ParentBuilder;
use PHPUnit\Framework\TestCase;

class ParentBuilderTest extends TestCase
{
    /**
     * @return array<int, array<string, string>>
     */
    private function template(): array
    {
        return [
            ['sku' => 'TS-RED-M', 'name' => 'T-Shirt Red M', 'price' => '19.90', 'qty' => '5', 'is_in_stock' => '0',
                'color' => 'Red', 'size' => 'M', 'visibility' => 'Not Visible Individually', 'url_key' => 't-shirt-red-m',
                'product_type' => 'simple', 'categories' => 'Default Category/Shirts', 'base_image' => 'a.jpg',
                'configurable_variations' => '', 'store_view_code' => ''],
            ['sku' => 'TS-RED-M', 'name' => 'T-Shirt Rot M', 'price' => '', 'qty' => '', 'is_in_stock' => '',
                'color' => '', 'size' => '', 'visibility' => '', 'url_key' => '', 'product_type' => '',
                'categories' => '', 'base_image' => '', 'configurable_variations' => '', 'store_view_code' => 'de'],
        ];
    }

    public function testParentRowsFromAVariant(): void
    {
        $rows = (new ParentBuilder())->build(
            'TS',
            $this->template(),
            ['TS-RED-M' => ['color' => 'Red', 'size' => 'M'], 'TS-BLUE-L' => ['color' => 'Blue', 'size' => 'L']],
            ['color', 'size'],
            ['' => 'T-Shirt', 'de' => 'T-Shirt DE']
        );

        self::assertCount(2, $rows);
        self::assertSame([
            'sku' => 'TS', 'name' => 'T-Shirt', 'price' => '19.90', 'qty' => '', 'is_in_stock' => '1', 'color' => '',
            'size' => '', 'visibility' => 'Catalog, Search', 'url_key' => '', 'product_type' => 'configurable',
            'categories' => 'Default Category/Shirts', 'base_image' => 'a.jpg',
            'configurable_variations' => 'sku=TS-RED-M,color=Red,size=M|sku=TS-BLUE-L,color=Blue,size=L',
            'store_view_code' => '',
        ], $rows[0]);
        self::assertSame('TS', $rows[1]['sku']);
        self::assertSame('T-Shirt DE', $rows[1]['name']);
        self::assertSame('', $rows[1]['visibility'], 'store views inherit the visibility');
        self::assertSame('', $rows[1]['configurable_variations']);
        self::assertSame('', $rows[1]['product_type']);
    }

    public function testClearedColumnsAndTheParentsOwnRow(): void
    {
        $builder = new ParentBuilder();
        $cleared = $builder->build('TS', $this->template(), ['TS-RED-M' => ['color' => 'Red']], ['color'], [], ['base_image']);
        self::assertSame('', $cleared[0]['base_image']);
        self::assertSame('T-Shirt Red M', $cleared[0]['name'], 'without a name the template name stays');

        $own = $builder->build('TS', $this->template(), ['TS-RED-M' => ['color' => 'Red']], ['color'], ['' => 'Ignored'], [], true);
        self::assertSame('T-Shirt Red M', $own[0]['name']);
        self::assertSame('t-shirt-red-m', $own[0]['url_key']);
        self::assertSame('Not Visible Individually', $own[0]['visibility']);
        self::assertSame('5', $own[0]['qty']);
        self::assertSame('0', $own[0]['is_in_stock']);
        self::assertSame('configurable', $own[0]['product_type']);
        self::assertSame('sku=TS-RED-M,color=Red', $own[0]['configurable_variations']);
        self::assertSame('', $own[0]['color'], 'a parent holds no variant values');
    }

    /**
     * @return array<string, array{0: string[], 1: string}>
     */
    public static function names(): array
    {
        return [
            'common words' => [['T-Shirt Basic Red M', 'T-Shirt Basic Blue L', 't-shirt basic Green S'], 'T-Shirt Basic'],
            'trailing separator' => [['Shirt - Red', 'Shirt - Blue'], 'Shirt'],
            'one name' => [['Shirt Red'], 'Shirt Red'],
            'nothing shared' => [['Red Shirt', 'Blue Shirt'], 'Red Shirt'],
            'same names' => [['Shirt', 'Shirt'], 'Shirt'],
            'empty' => [['', ' '], ''],
            'unicode' => [['Größe Übergröße 1', 'Größe Übergröße 2'], 'Größe Übergröße'],
        ];
    }

    /**
     * @param string[] $names
     * @param string $expected
     * @dataProvider names
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('names')]
    public function testCommonName(array $names, string $expected): void
    {
        self::assertSame($expected, (new ParentBuilder())->commonName($names));
    }
}
