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
use Iranimij\OpenImport\Model\Execution\RunFiles;
use Iranimij\OpenImport\Model\Execution\RunProcessor;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Iranimij\OpenImport\Model\Import\Order\OrderFile;
use Iranimij\OpenImport\Model\Import\Order\OrderImporter;
use Iranimij\OpenImport\Model\Reader\ReaderFactory;
use Iranimij\OpenImport\Model\Storage\ErrorLog;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Historic orders from a file: the round trip (export the fixture orders → import them with new numbers → export
 * the new ones → the same rows), orders with documents and stock, and the checks of the dry run.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class OrderImportTest extends TestCase
{
    /**
     * Columns the round trip must reproduce (ids, times, generated numbers and document numbers differ).
     */
    private const ROUND_TRIP = ['state', 'status', 'store_code', 'customer_email', 'customer_firstname',
        'customer_lastname', 'customer_group', 'customer_is_guest', 'order_currency_code', 'subtotal',
        'discount_amount', 'shipping_amount', 'tax_amount', 'grand_total', 'total_qty_ordered', 'shipping_method',
        'payment_method', 'billing_firstname', 'billing_lastname', 'billing_street', 'billing_city',
        'billing_postcode', 'billing_country_id', 'billing_telephone', 'shipping_city', 'item_sku', 'item_name',
        'item_product_type', 'item_qty_ordered', 'item_price', 'item_row_total'];

    private function var(): Filesystem\Directory\WriteInterface
    {
        return Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryWrite(DirectoryList::VAR_DIR);
    }

    private function file(string $content): string
    {
        $relative = 'openimport/it-orders-' . bin2hex(random_bytes(3)) . '.csv';
        $this->var()->writeFile($relative, $content);

        return $this->var()->getAbsolutePath($relative);
    }

    /**
     * @param array<string, mixed> $options
     * @param string $behavior
     * @param string[] $columns
     */
    private function importProfile(
        array $options = [],
        string $behavior = ProfileInterface::BEHAVIOR_ADD_UPDATE,
        array $columns = OrderFile::COLUMNS
    ): ProfileInterface {
        $mapping = [];
        foreach ($columns as $column) {
            $mapping[] = ['target' => $column, 'source' => $column];
        }
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Orders in ' . uniqid())
            ->setEntity(EntityRegistry::ORDER)
            ->setFormat('csv')
            ->setSourceType('upload')
            ->setBehavior($behavior)
            ->setOnError(ProfileInterface::ON_ERROR_SKIP)
            ->setSafetyThreshold(100)
            ->setImportOptions($options)
            ->setMapping($mapping);

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    private function exportProfile(string $skuPrefix = ''): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Orders out ' . uniqid())
            ->setDirection(ProfileInterface::DIRECTION_EXPORT)
            ->setEntity(EntityRegistry::ORDER)
            ->setFormat('csv')
            ->setExportOptions(['order' => ['rows' => 'item', 'items' => 'all', 'statuses' => 'processing, pending, complete, closed, canceled, holded']]);

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function process(ProfileInterface $profile, string $file = '', string $mode = RunInterface::MODE_LIVE): RunInterface
    {
        $options = $file === '' ? [] : ['file' => $file, 'original_name' => 'orders.csv'];
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue($profile, $mode, RunInterface::TRIGGER_CLI, $options);

        return Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function rows(RunInterface $run): array
    {
        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $run->getMessage());
        $file = Bootstrap::getObjectManager()->get(RunFiles::class)->absolute((string) $run->getOutputFile());
        $rows = [];
        foreach (Bootstrap::getObjectManager()->get(ReaderFactory::class)->create($file, 'csv')->rows() as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, string>> $rows
     */
    private function csv(array $rows): string
    {
        $columns = array_keys($rows[0]);
        $lines = [implode(';', $columns)];
        foreach ($rows as $row) {
            $lines[] = implode(';', array_map(
                static fn (string $column): string => '"' . str_replace('"', '""', (string) ($row[$column] ?? '')) . '"',
                $columns
            ));
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return string[]
     */
    private function errorMessages(RunInterface $run): array
    {
        $messages = [];
        foreach (Bootstrap::getObjectManager()->get(ErrorLog::class)->fetch((int) $run->getRunId(), 50) as $error) {
            $messages[] = $error->message;
        }

        return $messages;
    }

    #[DataFixture('Magento/Sales/_files/order_list.php')]
    public function testRoundTripExportImportExport(): void
    {
        // The Sales fixtures carry a subtotal of 100 over one item with a row total of 10; the import checks totals.
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $connection->update(
            $connection->getTableName('sales_order'),
            ['subtotal' => 10, 'base_subtotal' => 10, 'grand_total' => 10, 'base_grand_total' => 10, 'total_qty_ordered' => 2],
            ['increment_id IN (?)' => ['100000001', '100000002', '100000003', '100000004']]
        );
        $before = $this->rows($this->process($this->exportProfile()));
        $fixture = array_values(array_filter($before, static fn (array $row): bool => (int) $row['increment_id'] <= 100000004));
        self::assertNotEmpty($fixture);
        $file = $this->file($this->csv($fixture));

        $dry = $this->process($this->importProfile([OrderImporter::OPTION_NUMBER => 'generate']), $file, RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_SUCCESS, $dry->getStatus(), $dry->getMessage());
        self::assertSame(count(array_unique(array_column($fixture, 'increment_id'))), $dry->getCount('created'));

        $live = $this->process($this->importProfile([OrderImporter::OPTION_NUMBER => 'generate']), $file);
        self::assertSame(RunInterface::STATUS_SUCCESS, $live->getStatus(), $live->getMessage());
        self::assertSame($dry->getCount('created'), $live->getCount('created'));

        $after = $this->rows($this->process($this->exportProfile()));
        $imported = array_values(array_filter($after, static fn (array $row): bool => (int) $row['increment_id'] > 100000004));
        self::assertCount(count($fixture), $imported, 'one exported row per imported item');
        $strip = static fn (array $row): array => array_intersect_key($row, array_flip(self::ROUND_TRIP));
        self::assertSame(array_map($strip, $fixture), array_map($strip, $imported));
        $new = Bootstrap::getObjectManager()->create(Order::class)->loadByIncrementId($imported[0]['increment_id']);
        self::assertStringContainsString('Imported by OpenImport', (string) $new->getStatusHistoryCollection()->getFirstItem()->getComment());
    }

    #[DataFixture('Magento/Catalog/_files/product_simple.php')]
    public function testOrderWithDocumentsStockAndChecks(): void
    {
        $stock = Bootstrap::getObjectManager()->get(StockRegistryInterface::class);
        $qtyBefore = (float) $stock->getStockItemBySku('simple')->getQty();
        $header = 'increment_id;status;created_at;customer_email;customer_firstname;customer_lastname;order_currency_code;'
            . 'subtotal;shipping_amount;tax_amount;grand_total;total_paid;shipping_method;shipping_description;payment_method;'
            . 'tracking_numbers;tracking_carriers;billing_firstname;billing_lastname;billing_street;billing_city;billing_postcode;'
            . 'billing_country_id;billing_telephone;shipping_firstname;shipping_lastname;shipping_street;shipping_city;'
            . 'shipping_postcode;shipping_country_id;item_sku;item_name;item_qty_ordered;item_qty_invoiced;item_qty_shipped;'
            . "item_qty_refunded;item_price;item_row_total\n";
        $order = '"OI-9001";complete;2025-03-04 10:00:00;hist@example.com;Hanna;Beispiel;USD;20;5;0;25;25;flatrate_flatrate;'
            . 'Flat Rate;checkmo;DHL-1;dhl;Hanna;Beispiel;"Hauptstraße 1";Frankfurt;60311;DE;069123;Hanna;Beispiel;'
            . '"Hauptstraße 1";Frankfurt;60311;DE;simple;Simple Product;2;2;2;1;10;20' . "\n";
        $file = $this->file($header . $order);
        $columns = explode(';', trim($header));
        $profile = $this->importProfile([
            OrderImporter::OPTION_NUMBER => 'keep',
            OrderImporter::OPTION_DOCUMENTS => '1',
            OrderImporter::OPTION_STOCK => '1',
        ], ProfileInterface::BEHAVIOR_ADD_UPDATE, $columns);

        $run = $this->process($profile, $file);
        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $run->getMessage());
        self::assertSame(1, $run->getCount('created'));
        $order = Bootstrap::getObjectManager()->create(Order::class)->loadByIncrementId('OI-9001');
        self::assertNotEmpty($order->getId());
        self::assertSame('complete', $order->getStatus());
        self::assertSame('complete', $order->getState());
        self::assertSame(25.0, (float) $order->getGrandTotal());
        self::assertSame('2025-03-04 10:00:00', $order->getCreatedAt());
        self::assertSame('hist@example.com', $order->getCustomerEmail());
        self::assertSame(1, (int) $order->getCustomerIsGuest());
        self::assertCount(1, $order->getInvoiceCollection());
        self::assertCount(1, $order->getShipmentsCollection());
        self::assertCount(1, $order->getCreditmemosCollection());
        self::assertSame('DHL-1', $order->getTracksCollection()->getFirstItem()->getTrackNumber());
        $item = $order->getAllItems()[0];
        self::assertSame(2.0, (float) $item->getQtyInvoiced());
        self::assertSame(2.0, (float) $item->getQtyShipped());
        self::assertSame(1.0, (float) $item->getQtyRefunded());
        self::assertSame($qtyBefore - 2, (float) $stock->getStockItemBySku('simple')->getQty());
        self::assertSame('Hauptstraße 1', implode(', ', (array) $order->getShippingAddress()->getStreet()));

        // The same file again: skipped and reported, nothing created twice.
        $again = $this->process($profile, $file);
        self::assertSame(RunInterface::STATUS_PARTIAL, $again->getStatus(), $again->getMessage());
        self::assertSame(0, $again->getCount('created'));
        self::assertSame(['Order OI-9001 exists already; it is not imported again.'], $this->errorMessages($again));

        // A dry run names the problems: wrong totals, bad e-mail, unknown status, no items, missing parent.
        $bad = $this->file($header
            . '"OI-9002";shipped;;nobody;A;B;USD;20;5;0;99;;;;checkmo;;;A;B;Street 1;City;1;DE;1;;;;;;;simple;X;1;;;;10;10' . "\n"
            . '"OI-9003";complete;;x@example.com;A;B;USD;10;0;0;10;;;;checkmo;;;A;B;Street 1;City;1;DE;1;;;;;;;;;;;;;;' . "\n"
            . '"OI-9004";complete;;y@example.com;A;B;USD;10;0;0;10;;;;checkmo;;;A;B;Street 1;City;1;DE;1;;;;;;;simple;X;1;;;;10;10' . "\n"
            . '"OI-9004";complete;;y@example.com;A;B;USD;10;0;0;10;;;;checkmo;;;A;B;Street 1;City;1;DE;1;;;;;;;child;Y;1;;;;0;0' . "\n");
        $check = $this->process(
            $this->importProfile([], ProfileInterface::BEHAVIOR_ADD_UPDATE, $columns),
            $bad,
            RunInterface::MODE_DRY_RUN
        );
        self::assertSame(RunInterface::STATUS_FAILED, $check->getStatus(), $check->getMessage());
        $messages = implode("\n", $this->errorMessages($check));
        self::assertStringContainsString('The grand total 99 is not subtotal + shipping + tax - discount (25).', $messages);
        self::assertStringContainsString('"nobody" is not an e-mail address.', $messages);
        self::assertStringContainsString('Unknown order status "shipped".', $messages);
        self::assertStringContainsString('The order has no items', $messages);
        self::assertNull(Bootstrap::getObjectManager()->create(Order::class)->loadByIncrementId('OI-9004')->getId());
    }

    public function testOnlyAddBehaviours(): void
    {
        $this->importProfile([], ProfileInterface::BEHAVIOR_ONLY_ADD);
        $this->expectExceptionMessage('openimport_order can only be added: use Add/Update.');
        $this->importProfile([], ProfileInterface::BEHAVIOR_DELETE);
    }
}
