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
use Iranimij\OpenImport\Model\Storage\PreviewStore;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * "Quantities are changes" against a real store: Stock & prices (default stock) and stock per source (MSI).
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class StockChangeImportTest extends TestCase
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
     * @param array<int, array<string, string>> $rows
     * @return string
     */
    private function csv(array $rows): string
    {
        $file = sys_get_temp_dir() . '/openimport-stock-' . bin2hex(random_bytes(5)) . '.csv';
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
     * @param array<string, string> $options
     * @return ProfileInterface
     */
    private function profile(string $entity, array $mapping, array $options = []): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create();
        $profile->setName('Stock ' . bin2hex(random_bytes(4)))
            ->setEntity($entity)
            ->setFormat('csv')
            ->setBehavior('add_update')
            ->setOnError('skip')
            ->setAllowedErrors(10)
            ->setImportOptions($options)
            ->setMapping($mapping);

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    /**
     * @param ProfileInterface $profile
     * @param string $file
     * @param string $mode
     * @return RunInterface
     */
    private function runImport(ProfileInterface $profile, string $file, string $mode = RunInterface::MODE_LIVE): RunInterface
    {
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue(
            $profile,
            $mode,
            RunInterface::TRIGGER_CLI,
            ['file' => $file, 'original_name' => 'stock.csv']
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
     * @param string $prefix
     * @return void
     */
    private function products(string $prefix): void
    {
        $run = $this->runImport($this->profile('catalog_product', [
            ['target' => 'sku', 'source' => 'sku'],
            ['target' => 'name', 'source' => 'sku'],
            ['target' => 'price', 'source' => null, 'static' => '5'],
            ['target' => 'qty', 'source' => null, 'static' => '10'],
            ['target' => 'is_in_stock', 'source' => null, 'static' => '1'],
            ['target' => 'attribute_set_code', 'source' => null, 'static' => 'Default'],
            ['target' => 'product_type', 'source' => null, 'static' => 'simple'],
            ['target' => 'product_websites', 'source' => null, 'static' => 'base'],
        ]), $this->csv([['sku' => $prefix . '-1'], ['sku' => $prefix . '-2']]));
        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $this->describe($run));
    }

    private function qty(string $sku): float
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();

        return (float) $connection->fetchOne(
            'SELECT s.qty FROM ' . $connection->getTableName('cataloginventory_stock_item') . ' s
            JOIN ' . $connection->getTableName('catalog_product_entity') . ' e ON e.entity_id = s.product_id
            WHERE e.sku = ? AND s.stock_id = 1',
            [$sku]
        );
    }

    public function testDeliveriesAndSalesChangeTheStock(): void
    {
        $prefix = 'OI-ADD-' . bin2hex(random_bytes(3));
        $this->products($prefix);
        $profile = $this->profile(EntityRegistry::STOCK_PRICE, [
            ['target' => 'sku', 'source' => 'Artikel'],
            ['target' => 'qty', 'source' => 'Menge'],
        ], ['qty_mode' => 'add']);
        $file = $this->csv([
            ['Artikel' => $prefix . '-1', 'Menge' => '+5'],
            ['Artikel' => $prefix . '-2', 'Menge' => '-3'],
            ['Artikel' => $prefix . '-1', 'Menge' => '+2'],
            ['Artikel' => $prefix . '-2', 'Menge' => 'viele'],
        ]);

        $dry = $this->runImport($profile, $file, RunInterface::MODE_DRY_RUN);
        $diffs = [];
        foreach (Bootstrap::getObjectManager()->get(PreviewStore::class)->fetch((int) $dry->getRunId()) as $record) {
            $diffs[$record->identifier][] = $record->diff['qty'] ?? null;
        }
        // The preview shows the quantity Magento receives: one row per SKU with the sum of its changes.
        self::assertSame([['10', '17']], $diffs[$prefix . '-1'] ?? null, $this->describe($dry));
        self::assertSame(10.0, $this->qty($prefix . '-1'), 'a dry run changes nothing');

        $live = $this->runImport($profile, $file);
        self::assertSame(RunInterface::STATUS_PARTIAL, $live->getStatus(), $this->describe($live));
        self::assertSame(17.0, $this->qty($prefix . '-1'), '10 + 5 + 2');
        self::assertSame(7.0, $this->qty($prefix . '-2'), '10 - 3');
        self::assertStringContainsString('"viele" is not a change of quantity', $this->describe($live));

        // A second file adds again.
        $this->runImport($profile, $this->csv([['Artikel' => $prefix . '-2', 'Menge' => '1']]));
        self::assertSame(8.0, $this->qty($prefix . '-2'));
    }

    public function testStockPerSourceChanges(): void
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        if (!$connection->isTableExists($connection->getTableName('inventory_source_item'))) {
            self::markTestSkipped('MSI is not installed.');
        }
        $prefix = 'OI-ADS-' . bin2hex(random_bytes(3));
        $this->products($prefix);
        $profile = $this->profile(EntityRegistry::STOCK_SOURCES, [
            ['target' => 'sku', 'source' => 'sku'],
            ['target' => 'source_code', 'source' => null, 'static' => 'default'],
            ['target' => 'status', 'source' => null, 'static' => '1'],
            ['target' => 'quantity', 'source' => 'change'],
        ], ['qty_mode' => 'add']);

        $run = $this->runImport($profile, $this->csv([['sku' => $prefix . '-1', 'change' => '+4']]));
        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $this->describe($run));
        $quantity = (float) $connection->fetchOne(
            'SELECT quantity FROM ' . $connection->getTableName('inventory_source_item') . ' WHERE sku = ? AND source_code = ?',
            [$prefix . '-1', 'default']
        );
        self::assertSame(14.0, $quantity);
    }
}
