<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Preset;

use Iranimij\OpenImport\Model\Entity\EntityRegistry;
use Iranimij\OpenImport\Model\Filter\RowFilterCompiler;
use Iranimij\OpenImport\Model\Mapping\MappingCompiler;
use Iranimij\OpenImport\Model\Pipeline\ProfileCompiler;
use Iranimij\OpenImport\Model\Profile\ProfileJson;
use Iranimij\OpenImport\Model\Transform\TransformPool;
use Iranimij\OpenImport\Test\Unit\Model\ProfileStub;
use PHPUnit\Framework\TestCase;

/**
 * Every preset compiles against a file with the system's documented columns and maps sample rows as expected.
 * The sample rows are written from the public column lists of Shopify, WooCommerce and Magento 1.
 */
class PresetFilesTest extends TestCase
{
    /**
     * @param string $file
     * @return array<string, mixed>
     */
    private function preset(string $file): array
    {
        $json = json_decode((string) file_get_contents(__DIR__ . '/../../../../presets/' . $file), true);
        self::assertIsArray($json);
        self::assertSame(1, $json['openimport']);
        self::assertNotEmpty($json['preset']['label'] ?? '');
        self::assertNotEmpty($json['preset']['notes'] ?? '');

        return $json['profiles'][0];
    }

    /**
     * Maps the rows the filter keeps.
     *
     * @param array<string, mixed> $row profile row of the preset
     * @param array<int, array<string, string>> $rows
     * @return array<int, array<string, string>> default core rows, by source row index
     */
    private function map(array $row, array $rows): array
    {
        $profile = new ProfileStub($row + ['profile_id' => 1]);
        $compiler = new ProfileCompiler(new MappingCompiler(new TransformPool()), new RowFilterCompiler(), new EntityRegistry());
        $columns = array_keys($rows[0]);
        [$mapping] = $compiler->mapping($profile, $columns);
        $filter = $compiler->filter($profile, $columns);
        $out = [];
        foreach ($rows as $index => $values) {
            if (!$filter->keep($values)) {
                continue;
            }
            $mapped = $mapping->map($values);
            self::assertTrue($mapped->isValid(), json_encode($mapped->errors) ?: '');
            $out[$index] = $mapped->main();
        }

        return $out;
    }

    /**
     * @param string[] $columns
     * @param array<int, array<string, string>> $rows
     * @return array<int, array<string, string>>
     */
    private function rows(array $columns, array $rows): array
    {
        return array_map(static fn (array $row): array => array_merge(array_fill_keys($columns, ''), $row), $rows);
    }

    public function testEveryPresetHasOnlyKnownSettings(): void
    {
        $fields = (new \ReflectionClassConstant(ProfileJson::class, 'FIELDS'))->getValue();
        foreach (glob(__DIR__ . '/../../../../presets/*.json') ?: [] as $file) {
            $row = $this->preset(basename($file));
            self::assertSame([], array_diff(array_keys($row), $fields), basename($file));
            self::assertFalse($row['is_active'], basename($file) . ' starts inactive');
        }
    }

    public function testShopify(): void
    {
        $columns = ['Title', 'URL handle', 'Description', 'Vendor', 'Product category', 'Type', 'Tags',
            'Published on online store', 'Status', 'SKU', 'Barcodes', 'Option1 name', 'Option1 value', 'Price',
            'Compare-at price', 'Cost per item', 'Charge tax', 'Inventory tracker', 'Inventory quantity',
            'Continue selling when out of stock', 'Weight value (grams)', 'Product image URL', 'Variant image URL',
            'SEO title', 'SEO description'];
        $mapped = $this->map($this->preset('shopify.json'), $this->rows($columns, [
            ['Title' => 'Polo', 'URL handle' => 'polo', 'Description' => '<p>Soft</p>', 'Status' => 'active',
                'SKU' => 'POLO-S', 'Option1 name' => 'Size', 'Option1 value' => 'S', 'Price' => '20',
                'Compare-at price' => '25', 'Inventory quantity' => '3', 'Weight value (grams)' => '250',
                'Product image URL' => 'https://cdn.shopify.com/polo.jpg', 'SEO title' => 'Polo shirt'],
            ['URL handle' => 'polo', 'SKU' => 'POLO-M', 'Option1 name' => 'Size', 'Option1 value' => 'M', 'Price' => '20',
                'Inventory quantity' => '0', 'Continue selling when out of stock' => 'continue'],
            ['URL handle' => 'polo', 'Product image URL' => 'https://cdn.shopify.com/polo-back.jpg'],
            ['Title' => 'Mug', 'URL handle' => 'mug', 'Status' => 'draft', 'SKU' => 'MUG', 'Option1 name' => 'Title',
                'Option1 value' => 'Default Title', 'Price' => '8', 'Inventory quantity' => '0'],
        ]));

        self::assertSame([0, 1, 3], array_keys($mapped), 'the image-only row is skipped');
        self::assertSame(['POLO-S', 'Polo', '25', '20', '3', '1', '0.25', '1', 'polo'], [$mapped[0]['sku'],
            $mapped[0]['name'], $mapped[0]['price'], $mapped[0]['special_price'], $mapped[0]['qty'],
            $mapped[0]['is_in_stock'], $mapped[0]['weight'], $mapped[0]['product_online'],
            $mapped[0]['configurable:parent_sku']]);
        self::assertSame(['Polo', '<p>Soft</p>', 'Polo shirt', 'https://cdn.shopify.com/polo.jpg', '20', '', '1'], [
            $mapped[1]['name'], $mapped[1]['description'], $mapped[1]['meta_title'], $mapped[1]['base_image'],
            $mapped[1]['price'], $mapped[1]['special_price'], $mapped[1]['is_in_stock'],
        ], 'variant rows take the product values; selling on when out of stock keeps it in stock');
        self::assertSame(['Mug', '2', '', '0'], [$mapped[3]['name'], $mapped[3]['product_online'],
            $mapped[3]['configurable:parent_sku'], $mapped[3]['is_in_stock']], 'no variants, no parent');
    }

    public function testShopifyClassic(): void
    {
        $columns = ['Handle', 'Title', 'Body (HTML)', 'Vendor', 'Type', 'Tags', 'Published', 'Option1 Name',
            'Option1 Value', 'Variant SKU', 'Variant Grams', 'Variant Inventory Tracker', 'Variant Inventory Qty',
            'Variant Inventory Policy', 'Variant Fulfillment Service', 'Variant Price', 'Variant Compare At Price',
            'Image Src', 'Image Position', 'SEO Title', 'SEO Description', 'Cost per item', 'Status'];
        $mapped = $this->map($this->preset('shopify_classic.json'), $this->rows($columns, [
            ['Handle' => 'cap', 'Title' => 'Cap', 'Body (HTML)' => 'Warm', 'Option1 Name' => 'Color',
                'Option1 Value' => 'Red', 'Variant SKU' => 'CAP-R', 'Variant Price' => '12.5', 'Variant Inventory Qty' => '7',
                'Variant Inventory Policy' => 'deny', 'Status' => 'active', 'Image Src' => 'https://cdn.shopify.com/cap.jpg'],
            ['Handle' => 'cap', 'Option1 Value' => 'Blue', 'Variant SKU' => 'CAP-B', 'Variant Price' => '12.5',
                'Variant Inventory Qty' => '-1', 'Variant Inventory Policy' => 'deny'],
        ]));
        self::assertSame(['Cap', '12.5', '1', 'cap'], [$mapped[0]['name'], $mapped[0]['price'], $mapped[0]['is_in_stock'],
            $mapped[0]['configurable:parent_sku']]);
        self::assertSame(['Cap', 'Warm', '0', 'https://cdn.shopify.com/cap.jpg'], [$mapped[1]['name'],
            $mapped[1]['description'], $mapped[1]['is_in_stock'], $mapped[1]['base_image']]);
    }

    public function testWooCommerce(): void
    {
        $columns = ['ID', 'Type', 'SKU', 'Name', 'Published', 'Is featured?', 'Visibility in catalog',
            'Short description', 'Description', 'Date sale price starts', 'Date sale price ends', 'Tax status',
            'Tax class', 'In stock?', 'Stock', 'Backorders allowed?', 'Weight (kg)', 'Sale price', 'Regular price',
            'Categories', 'Tags', 'Images', 'Parent', 'Grouped products', 'Upsells', 'Cross-sells', 'External URL',
            'Position', 'Attribute 1 name', 'Attribute 1 value(s)', 'Attribute 1 visible', 'Attribute 1 global'];
        $mapped = $this->map($this->preset('woocommerce.json'), $this->rows($columns, [
            ['ID' => '10', 'Type' => 'variable', 'SKU' => 'HOODIE', 'Name' => 'Hoodie', 'Published' => '1',
                'Visibility in catalog' => 'visible', 'In stock?' => '1',
                'Images' => 'https://shop.example/a.jpg, https://shop.example/b.jpg'],
            ['ID' => '11', 'Type' => 'variation', 'SKU' => 'HOODIE-RED', 'Name' => 'Hoodie - Red', 'Published' => '1',
                'Visibility in catalog' => 'visible', 'In stock?' => '0', 'Stock' => '0', 'Regular price' => '45',
                'Sale price' => '39', 'Date sale price starts' => '2026-11-01 00:00:00', 'Parent' => 'HOODIE'],
            ['ID' => '12', 'Type' => 'simple', 'SKU' => '', 'Name' => 'No SKU'],
            ['ID' => '13', 'Type' => 'external', 'SKU' => 'AFF-1', 'Name' => 'Affiliate'],
            ['ID' => '14', 'Type' => 'simple, virtual', 'SKU' => 'EBOOK', 'Name' => 'E-book', 'Published' => '0',
                'Visibility in catalog' => 'hidden', 'Regular price' => '9'],
        ]));

        self::assertSame([0, 1, 4], array_keys($mapped), 'no SKU and external products are skipped');
        self::assertSame(['configurable', 'Catalog, Search', 'https://shop.example/a.jpg',
            'https://shop.example/a.jpg,https://shop.example/b.jpg'], [$mapped[0]['product_type'], $mapped[0]['visibility'],
            $mapped[0]['base_image'], $mapped[0]['additional_images']]);
        self::assertSame(['simple', 'HOODIE', '45', '39', '2026-11-01', '0'], [$mapped[1]['product_type'],
            $mapped[1]['configurable:parent_sku'], $mapped[1]['price'], $mapped[1]['special_price'],
            $mapped[1]['special_price_from_date'], $mapped[1]['is_in_stock']]);
        self::assertSame(['virtual', '2', 'Not Visible Individually'], [$mapped[4]['product_type'],
            $mapped[4]['product_online'], $mapped[4]['visibility']]);
    }

    public function testMagento1(): void
    {
        $columns = ['sku', '_store', '_attribute_set', '_type', '_category', '_root_category', '_product_websites',
            'description', 'image', 'is_in_stock', 'meta_description', 'meta_keyword', 'meta_title', 'name', 'price',
            'qty', 'short_description', 'small_image', 'special_from_date', 'special_price', 'special_to_date',
            'status', 'thumbnail', 'url_key', 'visibility', 'weight'];
        $mapped = $this->map($this->preset('magento1.json'), $this->rows($columns, [
            ['sku' => 'M1-1', '_attribute_set' => 'Default', '_type' => 'simple', '_category' => 'Shoes/Boots',
                '_root_category' => 'Default Category', '_product_websites' => 'base', 'name' => 'Boot', 'price' => '99.0000',
                'status' => '2', 'visibility' => '4', 'image' => '/b/o/boot.jpg', 'small_image' => 'no_selection',
                'special_from_date' => '2026-01-05 00:00:00', 'meta_keyword' => 'boot, leather'],
            ['sku' => '', '_category' => 'Sale', '_root_category' => 'Default Category'],
            ['sku' => 'M1-2', '_attribute_set' => 'Default', '_type' => 'virtual', '_category' => '', 'name' => 'Gift',
                'visibility' => '1', 'status' => '1'],
        ]));
        self::assertSame([0, 2], array_keys($mapped), 'continuation rows are skipped');
        self::assertSame(['Default Category/Shoes/Boots', '2', 'Catalog, Search', '/b/o/boot.jpg', '', '2026-01-05',
            'boot, leather'], [$mapped[0]['categories'], $mapped[0]['product_online'], $mapped[0]['visibility'],
            $mapped[0]['base_image'], $mapped[0]['small_image'], $mapped[0]['special_price_from_date'],
            $mapped[0]['meta_keywords']]);
        self::assertSame(['', 'Not Visible Individually', 'virtual'], [$mapped[2]['categories'],
            $mapped[2]['visibility'], $mapped[2]['product_type']]);
    }
}
