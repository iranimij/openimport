<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Pipeline;

use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Bridge\CoreImporter;
use Iranimij\OpenImport\Model\Bridge\ImportRequest;
use Iranimij\OpenImport\Model\Execution\RunProcessor;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Magento\ImportExport\Model\Import\Source\Csv;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * M1 oracle for the entities not covered by the core-bridge test: the same rows imported by core's CSV import
 * (System › Data Transfer › Import) and by an OpenImport profile through the whole pipeline give the same data.
 * Core imports prefix A, OpenImport prefix B; results are compared with the prefix removed.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class EntityOracleTest extends TestCase
{
    /**
     * @param array<int, array<string, string>> $rows
     * @return string relative path under var/
     */
    private function writeCsv(array $rows): string
    {
        $lines = [implode(',', array_keys($rows[0]))];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(
                static fn (string $value): string => '"' . str_replace('"', '""', $value) . '"',
                array_values($row)
            ));
        }
        $directory = Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryWrite(DirectoryList::VAR_DIR);
        $file = 'openimport-test/' . uniqid('entity-', true) . '.csv';
        $directory->writeFile($file, implode("\n", $lines) . "\n");

        return $file;
    }

    /**
     * @param string $entity
     * @param array<int, array<string, string>> $rows
     * @return void
     */
    private function core(string $entity, array $rows): void
    {
        $directory = Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryWrite(DirectoryList::VAR_DIR);
        $source = Bootstrap::getObjectManager()->create(Csv::class, [
            'file' => $directory->getAbsolutePath($this->writeCsv($rows)),
            'directory' => $directory,
        ]);
        $result = Bootstrap::getObjectManager()->get(CoreImporter::class)->run(new ImportRequest($entity), $source);
        self::assertTrue($result->imported, $entity . ': ' . ($result->errors[0]->message ?? 'not imported'));
    }

    /**
     * @param string $entity
     * @param array<int, array<string, string>> $rows
     * @return RunInterface
     */
    private function openImport(string $entity, array $rows): RunInterface
    {
        $mapping = array_map(
            static fn (string $column): array => ['target' => $column, 'source' => $column],
            array_keys($rows[0])
        );
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Oracle ' . $entity . ' ' . uniqid())
            ->setEntity($entity)
            ->setFormat('csv')
            ->setMapping($mapping);
        $profile = Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
        $directory = Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryRead(DirectoryList::VAR_DIR);
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue(
            $profile,
            RunInterface::MODE_LIVE,
            RunInterface::TRIGGER_CLI,
            ['file' => $directory->getAbsolutePath($this->writeCsv($rows)), 'original_name' => $entity . '.csv']
        );
        $run = Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $run->getMessage());

        return $run;
    }

    /**
     * Two simple products per prefix, created by core.
     *
     * @param string $prefix
     * @return void
     */
    private function products(string $prefix): void
    {
        $rows = [];
        foreach ([1, 2] as $i) {
            $rows[] = [
                'sku' => $prefix . '-' . $i,
                'attribute_set_code' => 'Default',
                'product_type' => 'simple',
                'product_websites' => 'base',
                'name' => 'Oracle ' . $prefix . ' ' . $i,
                'price' => '10',
                'qty' => '1',
            ];
        }
        $this->core('catalog_product', $rows);
    }

    /**
     * @param string $query with :prefix for the SKU / e-mail prefix
     * @param string $prefix
     * @return array<int, array<string, string>>
     */
    private function rows(string $query, string $prefix): array
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $rows = $connection->fetchAll(str_replace(':prefix', $connection->quote($prefix . '%'), $query));

        return array_map(static fn (array $row): array => array_map(
            static fn ($value): string => str_replace($prefix, 'X', (string) $value),
            $row
        ), $rows);
    }

    public function testAdvancedPricing(): void
    {
        $tag = strtoupper(substr(uniqid(), -6));
        [$a, $b] = ['OA' . $tag, 'OB' . $tag];
        $this->products($a);
        $this->products($b);
        $rows = static fn (string $prefix): array => [
            ['sku' => $prefix . '-1', 'tier_price_website' => 'All Websites [USD]', 'tier_price_customer_group' => 'ALL GROUPS',
                'tier_price_qty' => '5', 'tier_price' => '9', 'tier_price_value_type' => 'Fixed'],
            ['sku' => $prefix . '-1', 'tier_price_website' => 'All Websites [USD]', 'tier_price_customer_group' => 'General',
                'tier_price_qty' => '10', 'tier_price' => '10', 'tier_price_value_type' => 'Discount'],
            ['sku' => $prefix . '-2', 'tier_price_website' => 'All Websites [USD]', 'tier_price_customer_group' => 'ALL GROUPS',
                'tier_price_qty' => '3', 'tier_price' => '8.5', 'tier_price_value_type' => 'Fixed'],
        ];
        $this->core('advanced_pricing', $rows($a));
        $this->openImport('advanced_pricing', $rows($b));

        $query = "SELECT e.sku, t.all_groups, t.customer_group_id, t.qty, t.value, t.percentage_value, t.website_id
            FROM catalog_product_entity_tier_price t JOIN catalog_product_entity e ON e.entity_id = t.entity_id
            WHERE e.sku LIKE :prefix ORDER BY e.sku, t.qty";
        $expected = $this->rows($query, $a);
        self::assertCount(3, $expected);
        self::assertSame($expected, $this->rows($query, $b));
    }

    public function testCustomerAddresses(): void
    {
        $tag = strtolower(substr(uniqid(), -6));
        [$a, $b] = ['oa' . $tag, 'ob' . $tag];
        foreach ([$a, $b] as $prefix) {
            $this->core('customer', [[
                'email' => $prefix . '@example.com', '_website' => 'base', '_store' => 'default',
                'firstname' => 'Ada', 'lastname' => 'Oracle', 'group_id' => '1',
            ]]);
        }
        $rows = static fn (string $prefix): array => [
            ['_website' => 'base', '_email' => $prefix . '@example.com', '_entity_id' => '', 'firstname' => 'Ada',
                'lastname' => 'Oracle', 'street' => 'Hauptstraße 1', 'city' => 'Frankfurt', 'postcode' => '60311',
                'country_id' => 'DE', 'telephone' => '069 123', '_address_default_billing_' => '1',
                '_address_default_shipping_' => '1'],
            ['_website' => 'base', '_email' => $prefix . '@example.com', '_entity_id' => '', 'firstname' => 'Ada',
                'lastname' => 'Oracle', 'street' => 'Zeil 5', 'city' => 'Frankfurt', 'postcode' => '60313',
                'country_id' => 'DE', 'telephone' => '069 456', '_address_default_billing_' => '0',
                '_address_default_shipping_' => '0'],
        ];
        $this->core('customer_address', $rows($a));
        $this->openImport('customer_address', $rows($b));

        $query = "SELECT c.email, a.firstname, a.lastname, a.street, a.city, a.postcode, a.country_id, a.telephone,
                (c.default_billing = a.entity_id) AS is_billing, (c.default_shipping = a.entity_id) AS is_shipping
            FROM customer_address_entity a JOIN customer_entity c ON c.entity_id = a.parent_id
            WHERE c.email LIKE :prefix ORDER BY a.street";
        $expected = $this->rows($query, $a);
        self::assertCount(2, $expected);
        self::assertSame($expected, $this->rows($query, $b));
    }

    public function testStockSources(): void
    {
        $config = Bootstrap::getObjectManager()->get(\Magento\ImportExport\Model\Import\ConfigInterface::class);
        if (!isset($config->getEntities()['stock_sources'])) {
            self::markTestSkipped('MSI is not installed.');
        }
        $tag = strtoupper(substr(uniqid(), -6));
        [$a, $b] = ['OA' . $tag, 'OB' . $tag];
        $this->products($a);
        $this->products($b);
        $rows = static fn (string $prefix): array => [
            ['sku' => $prefix . '-1', 'source_code' => 'default', 'status' => '1', 'quantity' => '42'],
            ['sku' => $prefix . '-2', 'source_code' => 'default', 'status' => '0', 'quantity' => '0'],
        ];
        $this->core('stock_sources', $rows($a));
        $this->openImport('stock_sources', $rows($b));

        $query = "SELECT sku, source_code, quantity, status FROM inventory_source_item WHERE sku LIKE :prefix ORDER BY sku";
        $expected = $this->rows($query, $a);
        self::assertCount(2, $expected);
        self::assertSame($expected, $this->rows($query, $b));
    }
}
