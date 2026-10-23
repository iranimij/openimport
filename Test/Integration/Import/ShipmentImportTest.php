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
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Shipments and tracking numbers from a file: a partial shipment with a tracking number through Magento's own
 * ShipOrder service, the rest of the order without a SKU, a file imported twice (skipped), quantities above what
 * is left, unknown orders and carriers, and the behaviours a shipment profile refuses.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class ShipmentImportTest extends TestCase
{
    private function file(string $content): string
    {
        $var = Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryWrite(DirectoryList::VAR_DIR);
        $relative = 'openimport/it-ship-' . bin2hex(random_bytes(3)) . '.csv';
        $var->writeFile($relative, $content);

        return $var->getAbsolutePath($relative);
    }

    private function profile(string $behavior = ProfileInterface::BEHAVIOR_ADD_UPDATE): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Shipments ' . uniqid())
            ->setEntity(EntityRegistry::SHIPMENT)
            ->setFormat('csv')
            ->setSourceType('upload')
            ->setBehavior($behavior)
            ->setOnError(ProfileInterface::ON_ERROR_SKIP)
            ->setSafetyThreshold(100)
            ->setMapping([
                ['target' => 'increment_id', 'source' => 'Bestellnummer'],
                ['target' => 'item_sku', 'source' => 'sku'],
                ['target' => 'qty', 'source' => 'menge'],
                ['target' => 'carrier_code', 'source' => 'carrier'],
                ['target' => 'tracking_number', 'source' => 'tracking'],
                ['target' => 'title', 'source' => 'title'],
                ['target' => 'notify', 'source' => 'notify'],
                ['target' => 'comment', 'source' => 'comment'],
            ]);

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    private function process(ProfileInterface $profile, string $file, string $mode = RunInterface::MODE_LIVE): RunInterface
    {
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue(
            $profile,
            $mode,
            RunInterface::TRIGGER_CLI,
            ['file' => $file, 'original_name' => 'shipments.csv']
        );

        return Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
    }

    private function order(string $incrementId): Order
    {
        return Bootstrap::getObjectManager()->create(Order::class)->loadByIncrementId($incrementId);
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

    #[DataFixture('Magento/Sales/_files/order.php')]
    public function testShipsPartAndThenTheRestAndSkipsAFileImportedTwice(): void
    {
        $profile = $this->profile();
        $header = "Bestellnummer;sku;menge;carrier;tracking;title;notify;comment\n";
        $first = $this->file($header . "100000001;simple;1;custom;TRK-1;Kurier;0;Erster Teil\n");

        $dry = $this->process($profile, $first, RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_SUCCESS, $dry->getStatus(), $dry->getMessage());
        self::assertSame(1, $dry->getCount('created'));
        self::assertCount(0, $this->order('100000001')->getShipmentsCollection(), 'a dry run ships nothing');

        $live = $this->process($profile, $first);
        self::assertSame(RunInterface::STATUS_SUCCESS, $live->getStatus(), $live->getMessage());
        self::assertSame(1, $live->getCount('created'));
        $order = $this->order('100000001');
        self::assertCount(1, $order->getShipmentsCollection());
        $shipment = $order->getShipmentsCollection()->getFirstItem();
        self::assertSame(1.0, (float) $shipment->getTotalQty());
        $track = $order->getTracksCollection()->getFirstItem();
        self::assertSame('TRK-1', $track->getTrackNumber());
        self::assertSame('custom', $track->getCarrierCode());
        self::assertSame('Kurier', $track->getTitle());
        self::assertSame('Erster Teil', (string) $shipment->getCommentsCollection()->getFirstItem()->getComment());
        self::assertSame(1.0, (float) $this->rowItem($order)->getQtyShipped());

        // The same file again: the order already carries TRK-1, nothing is shipped twice.
        $again = $this->process($profile, $first);
        self::assertSame(RunInterface::STATUS_PARTIAL, $again->getStatus(), $again->getMessage());
        self::assertSame(0, $again->getCount('created'));
        self::assertCount(1, $this->order('100000001')->getShipmentsCollection());
        self::assertSame(['Order 100000001 already has tracking number TRK-1.'], $this->errorMessages($again));

        // The rest of the order without a SKU, two tracking numbers in two rows, customer notified.
        $rest = $this->file($header . "100000001;;;custom;TRK-2;;1;\n100000001;;;custom;TRK-3;Nachsendung;;\n");
        $second = $this->process($profile, $rest);
        self::assertSame(RunInterface::STATUS_SUCCESS, $second->getStatus(), $second->getMessage());
        self::assertSame(1, $second->getCount('created'), 'two rows of one order make one shipment');
        $order = $this->order('100000001');
        self::assertCount(2, $order->getShipmentsCollection());
        self::assertSame(2.0, (float) $this->rowItem($order)->getQtyShipped());
        $numbers = [];
        foreach ($order->getTracksCollection() as $track) {
            $numbers[] = $track->getTrackNumber();
        }
        self::assertEqualsCanonicalizing(['TRK-1', 'TRK-2', 'TRK-3'], $numbers);
        self::assertFalse($order->canShip());

        // Nothing left: skipped with a reason.
        $third = $this->process($profile, $this->file($header . "100000001;;;custom;TRK-4;;;\n"));
        self::assertSame(RunInterface::STATUS_PARTIAL, $third->getStatus());
        self::assertSame(['Order 100000001 cannot be shipped (nothing is left to ship).'], $this->errorMessages($third));
    }

    #[DataFixture('Magento/Sales/_files/order.php')]
    public function testRefusesBadRowsBeforeShippingAnything(): void
    {
        $profile = $this->profile();
        $header = "Bestellnummer;sku;menge;carrier;tracking;title;notify;comment\n";
        $run = $this->process($profile, $this->file(
            $header
            . "100000001;simple;5;custom;TRK-5;;;\n"
            . "100000001;other;1;custom;TRK-6;;;\n"
            . "999999999;simple;1;custom;TRK-7;;;\n"
            . "100000001;simple;1;pigeon;TRK-8;;;\n"
            . "100000001;simple;abc;custom;TRK-9;;;\n"
            . "100000001;;1;custom;TRK-10;;;\n"
        ));
        self::assertSame(RunInterface::STATUS_FAILED, $run->getStatus(), $run->getMessage());
        self::assertCount(0, $this->order('100000001')->getShipmentsCollection());
        $messages = $this->errorMessages($run);
        self::assertContains('Order 100000001: 5 of "simple" to ship, but only 2 left.', $messages);
        self::assertContains('Order 100000001 has no open item with SKU "other".', $messages);
        self::assertContains('Order 999999999 does not exist.', $messages);
        self::assertContains('"abc" is not a quantity.', $messages);
        self::assertContains('A quantity needs an item SKU.', $messages);
        self::assertTrue(
            (bool) array_filter($messages, static fn (string $m): bool => str_starts_with($m, 'Unknown carrier "pigeon". Carriers: custom, ')),
            implode(' | ', $messages)
        );
    }

    public function testOnlyAddBehavioursAreAllowed(): void
    {
        $this->profile(ProfileInterface::BEHAVIOR_ONLY_ADD);
        foreach ([ProfileInterface::BEHAVIOR_DELETE, ProfileInterface::BEHAVIOR_REPLACE, ProfileInterface::BEHAVIOR_ONLY_UPDATE] as $behavior) {
            try {
                $this->profile($behavior);
                self::fail($behavior . ' must be refused');
            } catch (LocalizedException $exception) {
                self::assertStringContainsString('openimport_shipment can only be added: use Add/Update.', $exception->getMessage());
            }
        }
    }

    private function rowItem(Order $order): Order\Item
    {
        foreach ($order->getAllItems() as $item) {
            if ($item->getSku() === 'simple') {
                return $item;
            }
        }
        self::fail('no simple item');
    }
}
