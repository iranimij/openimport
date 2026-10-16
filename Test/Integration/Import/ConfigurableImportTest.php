<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Import;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Entity\EntityRegistry;
use Iranimij\OpenImport\Model\Execution\RunProcessor;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Iranimij\OpenImport\Model\Storage\ErrorLog;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Configurable products from simple rows against a real store. The variation attributes are created with
 * OpenImport's own attribute import (global dropdowns in the Default set).
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class ConfigurableImportTest extends TestCase
{
    /**
     * @var string[]|null
     */
    private ?array $files = [];

    protected function setUp(): void
    {
        $attributes = $this->profile(EntityRegistry::ATTRIBUTE, [
            ['target' => 'attribute_code', 'source' => 'code'],
            ['target' => 'label', 'source' => 'label'],
            ['target' => 'frontend_input', 'source' => null, 'static' => 'select'],
            ['target' => 'scope', 'source' => null, 'static' => 'global'],
            ['target' => 'options', 'source' => 'options'],
            ['target' => 'attribute_set', 'source' => null, 'static' => 'Default'],
        ]);
        $run = $this->runImport($attributes, $this->csv([
            ['code' => 'oi_color', 'label' => 'OI Color', 'options' => 'Red|Blue|Green|Black'],
            ['code' => 'oi_size', 'label' => 'OI Size', 'options' => 'S|M|L'],
        ]));
        self::assertContains($run->getStatus(), [RunInterface::STATUS_SUCCESS], $this->describe($run));
    }

    protected function tearDown(): void
    {
        foreach ($this->files ?? [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @param array<int, array<string, string>> $rows
     * @return string
     */
    private function csv(array $rows): string
    {
        $file = sys_get_temp_dir() . '/openimport-cfg-' . bin2hex(random_bytes(5)) . '.csv';
        $handle = fopen($file, 'wb');
        fputcsv($handle, array_keys($rows[0]), ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_values($row), ',', '"', '');
        }
        fclose($handle);
        $this->files[] = $file;

        return $file;
    }

    /**
     * @param string $entity
     * @param array<int, array<string, mixed>> $mapping
     * @param array<string, string> $importOptions
     * @param bool $delta
     * @return ProfileInterface
     */
    private function profile(string $entity, array $mapping, array $importOptions = [], bool $delta = false): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create();
        $profile->setName('Configurable ' . bin2hex(random_bytes(4)))
            ->setEntity($entity)
            ->setFormat('csv')
            ->setBehavior('add_update')
            ->setOnError('skip')
            ->setAllowedErrors(10)
            ->setDelta($delta)
            ->setImportOptions($importOptions)
            ->setMapping($mapping);

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    /**
     * @param array<string, string> $options
     * @param bool $delta
     * @return ProfileInterface
     */
    private function productProfile(array $options = [], bool $delta = false): ProfileInterface
    {
        return $this->profile('catalog_product', [
            ['target' => 'sku', 'source' => 'sku'],
            ['target' => 'name', 'source' => 'name'],
            ['target' => 'store:default:name', 'source' => 'name_de'],
            ['target' => 'price', 'source' => 'price'],
            ['target' => 'qty', 'source' => null, 'static' => '10'],
            ['target' => 'is_in_stock', 'source' => null, 'static' => '1'],
            ['target' => 'oi_color', 'source' => 'color'],
            ['target' => 'oi_size', 'source' => 'size'],
            ['target' => 'product_type', 'source' => 'type'],
            ['target' => 'configurable:parent_sku', 'source' => 'parent'],
            ['target' => 'attribute_set_code', 'source' => null, 'static' => 'Default'],
            ['target' => 'product_websites', 'source' => null, 'static' => 'base'],
            ['target' => 'visibility', 'source' => null, 'static' => 'Catalog, Search'],
        ], $options + ['configurable' => '1', 'configurable_attributes' => 'oi_color,oi_size'], $delta);
    }

    /**
     * @param ProfileInterface $profile
     * @param string $file
     * @return RunInterface
     */
    private function runImport(ProfileInterface $profile, string $file): RunInterface
    {
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue(
            $profile,
            RunInterface::MODE_LIVE,
            RunInterface::TRIGGER_CLI,
            ['file' => $file, 'original_name' => 'variants.csv']
        );

        return Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
    }

    private function describe(RunInterface $run): string
    {
        $lines = [$run->getMessage()];
        foreach (Bootstrap::getObjectManager()->get(ErrorLog::class)->fetch((int) $run->getRunId()) as $error) {
            $lines[] = sprintf('row %d %s %s: %s', $error->sourceRow, $error->identifier, $error->field, $error->message);
        }

        return implode("\n", $lines);
    }

    /**
     * @param string $sku
     * @return array{type: string, name: string, visibility: string, url_key: string}|null
     */
    private function product(string $sku): ?array
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $row = $connection->fetchRow(
            'SELECT e.type_id, n.value AS name, v.value AS visibility, u.value AS url_key
            FROM ' . $connection->getTableName('catalog_product_entity') . ' e
            LEFT JOIN ' . $connection->getTableName('catalog_product_entity_varchar') . ' n
                ON n.entity_id = e.entity_id AND n.store_id = 0 AND n.attribute_id = (SELECT attribute_id FROM '
            . $connection->getTableName('eav_attribute') . ' WHERE attribute_code = \'name\' AND entity_type_id = 4)
            LEFT JOIN ' . $connection->getTableName('catalog_product_entity_int') . ' v
                ON v.entity_id = e.entity_id AND v.store_id = 0 AND v.attribute_id = (SELECT attribute_id FROM '
            . $connection->getTableName('eav_attribute') . ' WHERE attribute_code = \'visibility\' AND entity_type_id = 4)
            LEFT JOIN ' . $connection->getTableName('catalog_product_entity_varchar') . ' u
                ON u.entity_id = e.entity_id AND u.store_id = 0 AND u.attribute_id = (SELECT attribute_id FROM '
            . $connection->getTableName('eav_attribute') . ' WHERE attribute_code = \'url_key\' AND entity_type_id = 4)
            WHERE e.sku = ?',
            [$sku]
        );

        return $row === false ? null : [
            'type' => (string) $row['type_id'],
            'name' => (string) $row['name'],
            'visibility' => (string) $row['visibility'],
            'url_key' => (string) $row['url_key'],
        ];
    }

    /**
     * @param string $sku
     * @param string $store
     * @return string the store view's own name
     */
    private function storeName(string $sku, string $store): string
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();

        return (string) $connection->fetchOne(
            'SELECT n.value FROM ' . $connection->getTableName('catalog_product_entity_varchar') . ' n
            JOIN ' . $connection->getTableName('catalog_product_entity') . ' e ON e.entity_id = n.entity_id
            JOIN ' . $connection->getTableName('store') . ' s ON s.store_id = n.store_id
            WHERE e.sku = ? AND s.code = ? AND n.attribute_id = (SELECT attribute_id FROM '
            . $connection->getTableName('eav_attribute') . ' WHERE attribute_code = \'name\' AND entity_type_id = 4)',
            [$sku, $store]
        );
    }

    /**
     * @param string $parent
     * @return string[] SKUs of the linked variants, sorted
     */
    private function variants(string $parent): array
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $skus = $connection->fetchCol(
            'SELECT c.sku FROM ' . $connection->getTableName('catalog_product_super_link') . ' l
            JOIN ' . $connection->getTableName('catalog_product_entity') . ' p ON p.entity_id = l.parent_id
            JOIN ' . $connection->getTableName('catalog_product_entity') . ' c ON c.entity_id = l.product_id
            WHERE p.sku = ?',
            [$parent]
        );
        sort($skus);

        return $skus;
    }

    public function testVariantsBecomeAConfigurableProduct(): void
    {
        $parent = 'OI-CFG-' . bin2hex(random_bytes(3));
        $rows = [];
        foreach ([['Red', 'S'], ['Red', 'M'], ['Blue', 'M']] as [$color, $size]) {
            $rows[] = ['sku' => $parent . '-' . $color . '-' . $size, 'name' => 'Polo ' . $parent . ' ' . $color . ' ' . $size,
                'name_de' => 'Poloshirt ' . $parent . ' ' . $color . ' ' . $size,
                'price' => '29.90', 'color' => $color, 'size' => $size, 'type' => 'simple', 'parent' => $parent];
        }
        $profile = $this->productProfile([], true);

        $run = $this->runImport($profile, $this->csv($rows));
        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $this->describe($run));
        self::assertStringContainsString('Configurable products: 1 built from 3 variants.', $run->getMessage());
        self::assertSame(4, $run->getCount('created'), $this->describe($run));
        $product = $this->product($parent);
        self::assertSame('configurable', $product['type'] ?? null);
        self::assertSame('Polo ' . $parent, $product['name']);
        self::assertSame('4', $product['visibility'], 'Catalog, Search');
        self::assertSame([$parent . '-Blue-M', $parent . '-Red-M', $parent . '-Red-S'], $this->variants($parent));
        self::assertSame('Poloshirt ' . $parent, $this->storeName($parent, 'default'), 'the store view name too');
        $variant = $this->product($parent . '-Red-S');
        self::assertSame('1', $variant['visibility'] ?? null, 'Not Visible Individually');
        self::assertStringEndsWith(strtolower($parent) . '-red-s', $variant['url_key']);

        // Nothing changed: nothing is sent, the parent neither.
        $again = $this->runImport($profile, $this->csv($rows));
        self::assertSame(4, $again->getCount('unchanged'), $this->describe($again));

        // One more variant: it is linked and the others stay linked.
        $rows[] = ['sku' => $parent . '-Green-L', 'name' => 'Polo ' . $parent . ' Green L',
            'name_de' => 'Poloshirt ' . $parent . ' Green L', 'price' => '29.90',
            'color' => 'Green', 'size' => 'L', 'type' => 'simple', 'parent' => $parent];
        $more = $this->runImport($profile, $this->csv($rows));
        self::assertSame(RunInterface::STATUS_SUCCESS, $more->getStatus(), $this->describe($more));
        self::assertSame(
            [$parent . '-Blue-M', $parent . '-Green-L', $parent . '-Red-M', $parent . '-Red-S'],
            $this->variants($parent)
        );
    }

    public function testTheParentsOwnRowAndBadVariants(): void
    {
        $parent = 'OI-CFGW-' . bin2hex(random_bytes(3));
        $run = $this->runImport($this->productProfile(), $this->csv(array_map(
            static fn (array $row): array => $row + ['name_de' => ''],
            [
            ['sku' => $parent, 'name' => 'Hoodie ' . $parent, 'price' => '49', 'color' => '', 'size' => '',
                'type' => 'configurable', 'parent' => ''],
            ['sku' => $parent . '-1', 'name' => 'Hoodie black S ' . $parent, 'price' => '49', 'color' => 'Black',
                'size' => 'S', 'type' => 'simple', 'parent' => $parent],
            ['sku' => $parent . '-2', 'name' => 'Hoodie black S again ' . $parent, 'price' => '49', 'color' => 'Black',
                'size' => 'S', 'type' => 'simple', 'parent' => $parent],
            ['sku' => $parent . '-3', 'name' => 'Hoodie no size ' . $parent, 'price' => '49', 'color' => 'Black',
                'size' => '', 'type' => 'simple', 'parent' => $parent],
            ['sku' => $parent . '-4', 'name' => 'Hoodie purple ' . $parent, 'price' => '49', 'color' => 'Purple',
                'size' => 'M', 'type' => 'simple', 'parent' => $parent],
            ]
        )));

        self::assertSame(RunInterface::STATUS_PARTIAL, $run->getStatus(), $this->describe($run));
        self::assertSame(3, $run->getCount('errors'), $this->describe($run));
        $errors = [];
        foreach (Bootstrap::getObjectManager()->get(ErrorLog::class)->fetch((int) $run->getRunId()) as $error) {
            $errors[$error->identifier] = $error;
        }
        self::assertStringContainsString('has the same oi_color, oi_size', $errors[$parent . '-2']->message);
        self::assertSame('oi_size', $errors[$parent . '-3']->field);
        self::assertStringContainsString('"Purple" is not an option of oi_color', $errors[$parent . '-4']->message);
        $product = $this->product($parent);
        self::assertSame('configurable', $product['type'] ?? null);
        self::assertSame('Hoodie ' . $parent, $product['name'], 'the own row keeps its name');
        self::assertSame([$parent . '-1'], $this->variants($parent));
    }
}
