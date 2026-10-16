<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Import;

use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Model\Execution\RunProcessor;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Iranimij\OpenImport\Model\Preset\PresetRepository;
use Iranimij\OpenImport\Model\Storage\ErrorLog;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Profiles created from presets import files written from the systems' public column lists.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class PresetImportTest extends TestCase
{
    /**
     * @var string[]|null
     */
    private ?array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files ?? [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @param string[] $columns
     * @param array<int, array<string, string>> $rows
     * @return string
     */
    private function csv(array $columns, array $rows): string
    {
        $file = sys_get_temp_dir() . '/openimport-preset-' . bin2hex(random_bytes(5)) . '.csv';
        $handle = fopen($file, 'wb');
        fputcsv($handle, $columns, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn (string $column): string => $row[$column] ?? '', $columns), ',', '"', '');
        }
        fclose($handle);
        $this->files[] = $file;

        return $file;
    }

    private function importWith(string $preset, string $file): RunInterface
    {
        $profile = Bootstrap::getObjectManager()->get(PresetRepository::class)->create($preset);
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue(
            $profile,
            RunInterface::MODE_LIVE,
            RunInterface::TRIGGER_CLI,
            ['file' => $file, 'original_name' => $preset . '.csv']
        );
        $run = Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
        $lines = [$run->getMessage()];
        foreach (Bootstrap::getObjectManager()->get(ErrorLog::class)->fetch((int) $run->getRunId()) as $error) {
            $lines[] = sprintf('row %d %s %s: %s', $error->sourceRow, $error->identifier, $error->field, $error->message);
        }
        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), implode("\n", $lines));

        return $run;
    }

    private function product(string $sku): \Magento\Catalog\Api\Data\ProductInterface
    {
        return Bootstrap::getObjectManager()->create(ProductRepositoryInterface::class)->get($sku, false, 0, true);
    }

    public function testEveryPresetIsOfferedAndNamesStayUnique(): void
    {
        $presets = Bootstrap::getObjectManager()->get(PresetRepository::class);
        self::assertSame(['magento1', 'shopify', 'shopify_classic', 'woocommerce'], array_keys($presets->all()));
        $first = $presets->create('woocommerce');
        $second = $presets->create('woocommerce');
        self::assertNotSame($first->getName(), $second->getName());
        self::assertFalse($second->isActive());
        self::assertStringStartsWith('WooCommerce products', $second->getName());
    }

    public function testShopifyFile(): void
    {
        $tag = strtoupper(bin2hex(random_bytes(3)));
        $this->importWith('shopify', $this->csv(
            ['Title', 'URL handle', 'Description', 'Status', 'SKU', 'Option1 name', 'Option1 value', 'Price',
                'Compare-at price', 'Inventory quantity', 'Continue selling when out of stock', 'Weight value (grams)',
                'Product image URL', 'SEO title', 'SEO description', 'Cost per item'],
            [
                ['Title' => 'Tee ' . $tag, 'URL handle' => 'tee-' . $tag, 'Description' => 'Cotton', 'Status' => 'active',
                    'SKU' => 'SH-' . $tag . '-S', 'Option1 name' => 'Size', 'Option1 value' => 'S', 'Price' => '19',
                    'Compare-at price' => '25', 'Inventory quantity' => '4', 'Weight value (grams)' => '200'],
                ['URL handle' => 'tee-' . $tag, 'SKU' => 'SH-' . $tag . '-M', 'Option1 name' => 'Size',
                    'Option1 value' => 'M', 'Price' => '19', 'Inventory quantity' => '2'],
            ]
        ));
        $variant = $this->product('SH-' . $tag . '-M');
        self::assertSame('Tee ' . $tag, $variant->getName(), 'the title is filled down');
        self::assertSame(19.0, (float) $variant->getPrice());
        self::assertSame(25.0, (float) $this->product('SH-' . $tag . '-S')->getPrice());
        self::assertSame(19.0, (float) $this->product('SH-' . $tag . '-S')->getData('special_price'));
    }

    public function testWooCommerceFile(): void
    {
        $tag = strtoupper(bin2hex(random_bytes(3)));
        $this->importWith('woocommerce', $this->csv(
            ['ID', 'Type', 'SKU', 'Name', 'Published', 'Visibility in catalog', 'Short description', 'Description',
                'In stock?', 'Stock', 'Weight (kg)', 'Sale price', 'Regular price', 'Images', 'Parent',
                'Date sale price starts', 'Date sale price ends'],
            [
                ['ID' => '1', 'Type' => 'simple', 'SKU' => 'WC-' . $tag, 'Name' => 'Lamp ' . $tag, 'Published' => '1',
                    'Visibility in catalog' => 'visible', 'In stock?' => '1', 'Stock' => '5', 'Regular price' => '30',
                    'Weight (kg)' => '1.5'],
                ['ID' => '2', 'Type' => 'external', 'SKU' => 'WC-EXT-' . $tag, 'Name' => 'Affiliate ' . $tag],
            ]
        ));
        $lamp = $this->product('WC-' . $tag);
        self::assertSame('Lamp ' . $tag, $lamp->getName());
        self::assertSame(1.5, (float) $lamp->getWeight());
    }

    public function testMagento1File(): void
    {
        $tag = strtoupper(bin2hex(random_bytes(3)));
        $this->importWith('magento1', $this->csv(
            ['sku', '_store', '_attribute_set', '_type', '_category', '_root_category', '_product_websites',
                'description', 'image', 'is_in_stock', 'meta_description', 'meta_keyword', 'meta_title', 'name', 'price',
                'qty', 'short_description', 'small_image', 'special_from_date', 'special_price', 'special_to_date',
                'status', 'thumbnail', 'url_key', 'visibility', 'weight'],
            [
                ['sku' => 'M1-' . $tag, '_attribute_set' => 'Default', '_type' => 'simple', '_product_websites' => 'base',
                    'name' => 'Old shop ' . $tag, 'price' => '12.5000', 'qty' => '3.0000', 'is_in_stock' => '1',
                    'status' => '1', 'visibility' => '4', 'url_key' => 'old-shop-' . strtolower($tag),
                    'image' => 'no_selection', 'small_image' => 'no_selection', 'thumbnail' => 'no_selection'],
                ['sku' => '', '_store' => 'default', 'name' => 'Ignored'],
            ]
        ));
        $product = $this->product('M1-' . $tag);
        self::assertSame('Old shop ' . $tag, $product->getName());
        self::assertSame(12.5, (float) $product->getPrice());
    }
}
