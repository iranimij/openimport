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
 * "The file is the complete list" against a real store. Every test uses its own supplier (an option of the
 * oi_supplier dropdown) as the scope, so the tests never touch each other's products.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class MissingProductsImportTest extends TestCase
{
    /**
     * @var string[]|null
     */
    private ?array $files = [];

    private ?string $supplier = null;

    private ?string $other = null;

    protected function setUp(): void
    {
        $this->supplier = 'Acme ' . bin2hex(random_bytes(3));
        $this->other = 'Beta ' . bin2hex(random_bytes(3));
        $run = $this->runImport($this->profile(EntityRegistry::ATTRIBUTE, [
            ['target' => 'attribute_code', 'source' => 'code'],
            ['target' => 'label', 'source' => 'label'],
            ['target' => 'frontend_input', 'source' => null, 'static' => 'select'],
            ['target' => 'scope', 'source' => null, 'static' => 'global'],
            ['target' => 'options', 'source' => 'options'],
            ['target' => 'attribute_set', 'source' => null, 'static' => 'Default'],
        ]), $this->csv([['code' => 'oi_supplier', 'label' => 'OI Supplier',
            'options' => $this->supplier . '|' . $this->other]]));
        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $this->describe($run));
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
        $file = sys_get_temp_dir() . '/openimport-missing-' . bin2hex(random_bytes(5)) . '.csv';
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
     * @param int|null $safety
     * @return ProfileInterface
     */
    private function profile(string $entity, array $mapping, array $importOptions = [], ?int $safety = 100): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create();
        $profile->setName('Missing ' . bin2hex(random_bytes(4)))
            ->setEntity($entity)
            ->setFormat('csv')
            ->setBehavior('add_update')
            ->setOnError('stop')
            ->setSafetyThreshold($safety)
            ->setImportOptions($importOptions)
            ->setMapping($mapping);

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    /**
     * @param array<string, string> $options
     * @param int|null $safety
     * @return ProfileInterface
     */
    private function productProfile(array $options = [], ?int $safety = 100): ProfileInterface
    {
        return $this->profile('catalog_product', [
            ['target' => 'sku', 'source' => 'sku'],
            ['target' => 'name', 'source' => 'name'],
            ['target' => 'price', 'source' => null, 'static' => '10'],
            ['target' => 'qty', 'source' => null, 'static' => '7'],
            ['target' => 'is_in_stock', 'source' => null, 'static' => '1'],
            ['target' => 'oi_supplier', 'source' => 'supplier'],
            ['target' => 'attribute_set_code', 'source' => null, 'static' => 'Default'],
            ['target' => 'product_type', 'source' => null, 'static' => 'simple'],
            ['target' => 'product_websites', 'source' => null, 'static' => 'base'],
            ['target' => 'product_online', 'source' => null, 'static' => '1'],
        ], $options, $safety);
    }

    /**
     * @param ProfileInterface $profile
     * @param string $file
     * @param string $mode
     * @param array<string, mixed> $options
     * @return RunInterface
     */
    private function runImport(
        ProfileInterface $profile,
        string $file,
        string $mode = RunInterface::MODE_LIVE,
        array $options = []
    ): RunInterface {
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue(
            $profile,
            $mode,
            RunInterface::TRIGGER_CLI,
            $options + ['file' => $file, 'original_name' => 'complete.csv']
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
     * @return array<int, array<string, string>> five products of the test's supplier and one of the other supplier
     */
    private function shop(string $prefix): array
    {
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = ['sku' => $prefix . '-' . $i, 'name' => 'Missing test ' . $prefix . ' ' . $i, 'supplier' => (string) $this->supplier];
        }
        $rows[] = ['sku' => $prefix . '-OTHER', 'name' => 'Missing test ' . $prefix . ' other', 'supplier' => (string) $this->other];
        $run = $this->runImport($this->productProfile(), $this->csv($rows));
        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $this->describe($run));

        return $rows;
    }

    /**
     * @param string $sku
     * @return array{status: int, in_stock: int}|null
     */
    private function state(string $sku): ?array
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $row = $connection->fetchRow(
            'SELECT s.value AS status, i.is_in_stock FROM ' . $connection->getTableName('catalog_product_entity') . ' e
            LEFT JOIN ' . $connection->getTableName('catalog_product_entity_int') . ' s
                ON s.entity_id = e.entity_id AND s.store_id = 0 AND s.attribute_id = (SELECT attribute_id FROM '
            . $connection->getTableName('eav_attribute') . ' WHERE attribute_code = \'status\' AND entity_type_id = 4)
            LEFT JOIN ' . $connection->getTableName('cataloginventory_stock_item') . ' i ON i.product_id = e.entity_id
            WHERE e.sku = ?',
            [$sku]
        );

        return $row === false ? null : ['status' => (int) $row['status'], 'in_stock' => (int) $row['is_in_stock']];
    }

    /**
     * @param string $action
     * @return array<string, string>
     */
    private function options(string $action): array
    {
        return ['missing_action' => $action, 'missing_scope' => 'attribute', 'missing_attribute' => 'oi_supplier',
            'missing_value' => (string) $this->supplier];
    }

    public function testMissingProductsOfTheSupplierAreDisabledAndTheDryRunSaysSo(): void
    {
        $prefix = 'OI-MIS-' . bin2hex(random_bytes(3));
        $rows = $this->shop($prefix);
        $profile = $this->productProfile($this->options('disable'));
        $file = $this->csv(array_slice($rows, 0, 3));

        $dry = $this->runImport($profile, $file, RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_SUCCESS, $dry->getStatus(), $this->describe($dry));
        self::assertStringContainsString(
            sprintf('Products missing from the file: would disable 2 (%s-4, %s-5).', $prefix, $prefix),
            $dry->getMessage()
        );
        self::assertSame(1, $this->state($prefix . '-4')['status'] ?? null, 'a dry run changes nothing');

        $live = $this->runImport($profile, $file);
        self::assertSame(RunInterface::STATUS_SUCCESS, $live->getStatus(), $this->describe($live));
        self::assertStringContainsString(
            sprintf('Products missing from the file: disabled 2 (%s-4, %s-5).', $prefix, $prefix),
            $live->getMessage()
        );
        self::assertSame(2, $this->state($prefix . '-4')['status'] ?? null);
        self::assertSame(2, $this->state($prefix . '-5')['status'] ?? null);
        self::assertSame(1, $this->state($prefix . '-1')['status'] ?? null);
        self::assertSame(1, $this->state($prefix . '-OTHER')['status'] ?? null, 'another supplier is untouched');

        // Disabled products are not counted again.
        $again = $this->runImport($profile, $file);
        self::assertStringNotContainsString('Products missing from the file', $again->getMessage());
    }

    public function testOutOfStockAndTheSafetyLimit(): void
    {
        $prefix = 'OI-MIO-' . bin2hex(random_bytes(3));
        $rows = $this->shop($prefix);
        $profile = $this->productProfile($this->options('out_of_stock'), 20);
        $file = $this->csv(array_slice($rows, 0, 3));

        $waiting = $this->runImport($profile, $file);
        self::assertSame(RunInterface::STATUS_NEEDS_CONFIRMATION, $waiting->getStatus(), $this->describe($waiting));
        self::assertStringContainsString('Type OUT OF STOCK to confirm.', $waiting->getMessage());
        self::assertSame(1, $this->state($prefix . '-4')['in_stock'] ?? null);

        $confirmed = $this->runImport($profile, $file, RunInterface::MODE_LIVE, ['confirmed' => true]);
        self::assertSame(RunInterface::STATUS_SUCCESS, $confirmed->getStatus(), $this->describe($confirmed));
        self::assertSame(0, $this->state($prefix . '-4')['in_stock'] ?? null);
        self::assertSame(1, $this->state($prefix . '-2')['in_stock'] ?? null);
        self::assertSame(1, $this->state($prefix . '-OTHER')['in_stock'] ?? null);
    }

    public function testDeleteAndRowErrorsStopIt(): void
    {
        $prefix = 'OI-MID-' . bin2hex(random_bytes(3));
        $rows = $this->shop($prefix);
        $profile = $this->productProfile($this->options('delete'));

        $broken = $this->runImport($profile, $this->csv([$rows[0], ['sku' => $prefix . '-1', 'name' => 'x',
            'supplier' => 'No such supplier']]));
        self::assertNotNull($this->state($prefix . '-5'), 'a run with row errors deletes nothing');

        $run = $this->runImport($profile, $this->csv(array_slice($rows, 0, 4)));
        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $this->describe($run));
        self::assertNull($this->state($prefix . '-5'));
        self::assertNotNull($this->state($prefix . '-4'));
        self::assertNotNull($this->state($prefix . '-OTHER'));
        self::assertSame(1, $run->getCount('deleted'), $this->describe($run) . ' / ' . $this->describe($broken));
    }

    public function testAFileWithoutRowsChangesNothing(): void
    {
        $prefix = 'OI-MIE-' . bin2hex(random_bytes(3));
        $this->shop($prefix);
        $file = sys_get_temp_dir() . '/openimport-missing-' . bin2hex(random_bytes(5)) . '.csv';
        file_put_contents($file, "sku,name,supplier\n");
        $this->files[] = $file;

        $run = $this->runImport($this->productProfile($this->options('disable')), $file);
        self::assertStringContainsString('nothing changed because the file has no rows', $this->describe($run));
        self::assertSame(1, $this->state($prefix . '-5')['status'] ?? null, 'nothing is disabled by an empty feed');
    }
}
