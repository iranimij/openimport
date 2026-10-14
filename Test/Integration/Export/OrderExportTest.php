<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Export;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Execution\RunFiles;
use Iranimij\OpenImport\Model\Execution\RunProcessor;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Iranimij\OpenImport\Model\Reader\ReaderFactory;
use Iranimij\OpenImport\Model\Storage\ExportMarkStore;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\ShipmentFactory;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * OpenImport's order export against the sales tables (direct SQL oracle): columns, items, addresses, payment,
 * shipments with tracking, status and date filters, "only new" marks, one row per order, and the folder destination.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class OrderExportTest extends TestCase
{
    /**
     * @param array<string, mixed> $order export_options.order
     * @param array<string, mixed> $export more export_options
     * @param array<string, mixed> $data profile data
     * @return ProfileInterface
     */
    private function profile(array $order = [], array $export = [], array $data = []): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Orders ' . uniqid())
            ->setDirection(ProfileInterface::DIRECTION_EXPORT)
            ->setEntity('openimport_order')
            ->setFormat($data['format'] ?? 'csv')
            ->setMapping($data['mapping'] ?? [])
            ->setRowFilters($data['row_filters'] ?? [])
            ->setExportOptions(['order' => $order] + $export);

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    private function process(ProfileInterface $profile, string $mode = RunInterface::MODE_LIVE): RunInterface
    {
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue($profile, $mode, RunInterface::TRIGGER_CLI);

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
     * @return array<string, mixed>
     */
    private function sql(string $query, array $bind = []): array
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();

        return (array) $connection->fetchRow($query, $bind);
    }

    #[DataFixture('Magento/Sales/_files/order_list.php')]
    public function testOrdersMatchTheSalesTables(): void
    {
        $order = Bootstrap::getObjectManager()->create(Order::class)->loadByIncrementId('100000001');
        self::assertNotEmpty($order->getId());
        $shipment = Bootstrap::getObjectManager()->get(ShipmentFactory::class)->create(
            $order,
            array_combine(
                array_map(static fn ($item): int => (int) $item->getId(), $order->getAllItems()),
                array_map(static fn ($item): float => (float) $item->getQtyOrdered(), $order->getAllItems())
            )
        );
        $shipment->addTrack(Bootstrap::getObjectManager()->get(TrackFactory::class)->create()
            ->setCarrierCode('dhl')->setTitle('DHL')->setTrackNumber('JD0001'));
        $shipment->save();

        $rows = $this->rows($this->process($this->profile(['statuses' => 'processing, pending, complete, closed'])));
        $exported = array_column($rows, 'increment_id');
        self::assertContains('100000001', $exported);
        self::assertContains('100000002', $exported);
        $row = $rows[array_search('100000001', $exported, true)];
        $db = $this->sql(
            'SELECT o.status, o.grand_total, o.customer_email, o.order_currency_code, p.method, a.city, a.street, i.sku, i.qty_ordered
             FROM sales_order o JOIN sales_order_payment p ON p.parent_id = o.entity_id
             JOIN sales_order_address a ON a.parent_id = o.entity_id AND a.address_type = \'billing\'
             JOIN sales_order_item i ON i.order_id = o.entity_id AND i.parent_item_id IS NULL
             WHERE o.increment_id = :id ORDER BY i.item_id LIMIT 1',
            ['id' => '100000001']
        );
        self::assertSame($db['status'], $row['status']);
        self::assertSame((float) $db['grand_total'], (float) $row['grand_total']);
        self::assertSame($db['customer_email'], $row['customer_email']);
        self::assertSame($db['order_currency_code'], $row['order_currency_code']);
        self::assertSame($db['method'], $row['payment_method']);
        self::assertSame($db['city'], $row['billing_city']);
        self::assertSame(str_replace("\n", ', ', (string) $db['street']), $row['billing_street']);
        self::assertSame($db['sku'], $row['item_sku']);
        self::assertSame((float) $db['qty_ordered'], (float) $row['item_qty_ordered']);
        self::assertSame((string) $shipment->getIncrementId(), $row['shipment_increment_ids']);
        self::assertSame('JD0001', $row['tracking_numbers']);
        self::assertSame('dhl', $row['tracking_carriers']);
        self::assertArrayNotHasKey('remote_ip_', $row);
    }

    #[DataFixture('Magento/Sales/_files/order_list.php')]
    public function testFiltersOnlyNewAndOneRowPerOrder(): void
    {
        $perOrder = $this->rows($this->process($this->profile(['rows' => 'order'])));
        $perOrderIds = array_column($perOrder, 'increment_id');
        self::assertSame(count(array_unique($perOrderIds)), count($perOrderIds), 'one row per order');
        self::assertArrayNotHasKey('item_sku', $perOrder[0]);

        // Filter by a status the fixture orders really have (core's order list differs between versions).
        $wanted = (string) $perOrder[0]['status'];
        $status = $this->rows($this->process($this->profile(['statuses' => $wanted])));
        self::assertNotEmpty($status);
        self::assertSame([$wanted], array_values(array_unique(array_column($status, 'status'))));

        $none = $this->process($this->profile(['from' => '2099-01-01']));
        self::assertStringContainsString('Nothing to export', $none->getMessage());

        $onlyNew = $this->profile(['only' => 'new', 'rows' => 'order']);
        $first = $this->rows($this->process($onlyNew));
        $marks = Bootstrap::getObjectManager()->get(ExportMarkStore::class);
        self::assertSame(count($first), $marks->count((int) $onlyNew->getProfileId(), 'order'));
        $second = $this->process($onlyNew);
        self::assertStringContainsString('Nothing to export', $second->getMessage(), 'everything is exported already');

        $dry = $this->process($this->profile(['only' => 'new', 'rows' => 'order']), RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_SUCCESS, $dry->getStatus());
        self::assertSame(0, $marks->count((int) $dry->getProfileId(), 'order'), 'a dry run marks nothing');
    }

    #[DataFixture('Magento/Sales/_files/order_list.php')]
    public function testFolderDestinationAndMapping(): void
    {
        $tag = bin2hex(random_bytes(3));
        $profile = $this->profile(
            ['rows' => 'order'],
            ['destination' => 'folder', 'path' => 'it-out-' . $tag, 'file_name' => 'orders-{run}.{ext}'],
            ['mapping' => [
                ['target' => 'Bestellnummer', 'source' => 'increment_id'],
                ['target' => 'Summe', 'source' => 'grand_total', 'transforms' => [['name' => 'number', 'args' => ['2', ',']]]],
            ]]
        );
        $run = $this->process($profile);
        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $run->getMessage());
        $var = Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryRead(DirectoryList::VAR_DIR);
        $written = 'openimport/it-out-' . $tag . '/orders-' . $run->getRunId() . '.csv';
        self::assertTrue($var->isFile($written), $run->getMessage());
        self::assertStringContainsString('Written to', $run->getMessage());
        $content = $var->readFile($written);
        self::assertStringStartsWith("Bestellnummer,Summe\n", $content);
        // The decimal comma makes the cell a quoted one.
        self::assertMatchesRegularExpression('/^1000000\d,"\d+,\d{2}"$/m', $content);
    }
}
