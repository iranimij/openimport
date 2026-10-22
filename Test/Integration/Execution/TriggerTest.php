<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Execution;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Trigger\TriggerEvents;
use Iranimij\OpenImport\Model\Trigger\TriggerGuard;
use Iranimij\OpenImport\Model\Trigger\TriggerQueue;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Triggers: shop events queue one live run of every switched-on profile that runs after them; a waiting run is not
 * queued twice; status changes respect the chosen statuses; what OpenImport's own runs write starts nothing.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class TriggerTest extends TestCase
{
    /**
     * Profiles this test made, removed afterwards so they never run after other tests' saves. Nullable: Magento's
     * test framework sets the properties of a test case to null once its tests are done.
     *
     * @var int[]|null
     */
    private ?array $made = [];

    protected function tearDown(): void
    {
        if (empty($this->made)) {
            return;
        }
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $connection->delete($connection->getTableName('openimport_run'), ['profile_id IN (?)' => $this->made]);
        $connection->delete($connection->getTableName('openimport_profile'), ['profile_id IN (?)' => $this->made]);
        $this->made = [];
    }

    /**
     * @param string[] $events
     * @param string[] $statuses
     */
    private function profile(array $events, bool $active = true, string $entity = 'catalog_product', array $statuses = []): ProfileInterface
    {
        $tag = bin2hex(random_bytes(3));
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Trigger ' . $tag)
            ->setDirection(ProfileInterface::DIRECTION_EXPORT)
            ->setEntity($entity)
            ->setFormat('csv')
            ->setExportOptions(['destination' => 'folder', 'path' => 'it-trigger-' . $tag])
            ->setTriggers(['events' => $events, 'order_statuses' => $statuses])
            ->setIsActive($active);

        $profile = Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
        $this->made[] = (int) $profile->getProfileId();

        return $profile;
    }

    /**
     * @return array<int, array<string, mixed>> queued runs of the profile
     */
    private function queued(ProfileInterface $profile): array
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();

        return $connection->fetchAll(
            $connection->select()->from($connection->getTableName('openimport_run'), ['run_id', 'run_trigger', 'requested_by', 'mode'])
                ->where('profile_id = ?', (int) $profile->getProfileId())
                ->where('status = ?', RunInterface::STATUS_QUEUED)
        );
    }

    #[DataFixture('Magento/Catalog/_files/product_simple.php')]
    public function testProductAndStockSavesQueueOneRunOfSwitchedOnProfiles(): void
    {
        $products = $this->profile([TriggerEvents::PRODUCT_SAVED]);
        $stock = $this->profile([TriggerEvents::STOCK_CHANGED]);
        $off = $this->profile([TriggerEvents::PRODUCT_SAVED], false);
        $customers = $this->profile([TriggerEvents::CUSTOMER_SAVED], true, 'customer');

        $repository = Bootstrap::getObjectManager()->get(ProductRepositoryInterface::class);
        foreach (['First', 'Second', 'Third'] as $name) {
            $product = $repository->get('simple', true, null, true);
            $product->setName('Simple ' . $name);
            $repository->save($product);
        }
        $runs = $this->queued($products);
        self::assertCount(1, $runs, 'three saves, one waiting run');
        self::assertSame(['event', 'product saved', 'live'], [$runs[0]['run_trigger'], $runs[0]['requested_by'], $runs[0]['mode']]);
        self::assertSame([], $this->queued($off), 'switched off');
        self::assertSame([], $this->queued($customers), 'another event');

        $registry = Bootstrap::getObjectManager()->get(StockRegistryInterface::class);
        $item = $registry->getStockItemBySku('simple');
        $item->setQty(77);
        $registry->updateStockItemBySku('simple', $item);
        $runs = $this->queued($stock);
        self::assertCount(1, $runs);
        self::assertSame('stock changed', $runs[0]['requested_by']);
    }

    #[DataFixture('Magento/Sales/_files/order.php')]
    public function testOrderStatusChangesRunForTheChosenStatuses(): void
    {
        $any = $this->profile([TriggerEvents::ORDER_STATUS_CHANGED], true, 'openimport_order');
        $complete = $this->profile([TriggerEvents::ORDER_STATUS_CHANGED], true, 'openimport_order', ['complete']);
        $placed = $this->profile([TriggerEvents::ORDER_PLACED], true, 'openimport_order');

        $orders = Bootstrap::getObjectManager()->get(OrderRepositoryInterface::class);
        $order = Bootstrap::getObjectManager()->create(Order::class)->loadByIncrementId('100000001');
        $order->setStatus('holded');
        $orders->save($order);
        self::assertCount(1, $this->queued($any));
        self::assertSame([], $this->queued($complete), 'holded is not one of its statuses');
        self::assertSame([], $this->queued($placed), 'a status change is not a new order');

        $order = Bootstrap::getObjectManager()->create(Order::class)->loadByIncrementId('100000001');
        $order->setStatus('complete');
        $orders->save($order);
        self::assertCount(1, $this->queued($complete));

        // An order saved with the status it had changes nothing.
        $quiet = $this->profile([TriggerEvents::ORDER_STATUS_CHANGED], true, 'openimport_order');
        $order = Bootstrap::getObjectManager()->create(Order::class)->loadByIncrementId('100000001');
        $order->setCustomerNote('note');
        $orders->save($order);
        self::assertSame([], $this->queued($quiet));

        Bootstrap::getObjectManager()->get(ManagerInterface::class)->dispatch('sales_order_place_after', ['order' => $order]);
        self::assertCount(1, $this->queued($placed));
    }

    #[DataFixture('Magento/Customer/_files/customer.php')]
    public function testCustomerSavedAndRegistered(): void
    {
        $saved = $this->profile([TriggerEvents::CUSTOMER_SAVED], true, 'customer');
        $registered = $this->profile([TriggerEvents::CUSTOMER_REGISTERED], true, 'customer');

        $customers = Bootstrap::getObjectManager()->get(CustomerRepositoryInterface::class);
        $customer = $customers->get('customer@example.com');
        $customer->setFirstname('Triggered');
        $customers->save($customer);
        self::assertCount(1, $this->queued($saved));
        self::assertSame([], $this->queued($registered), 'a save is not a registration');

        // The storefront's registration raises this event after the account exists.
        Bootstrap::getObjectManager()->get(ManagerInterface::class)
            ->dispatch('customer_register_success', ['customer' => $customer]);
        self::assertCount(1, $this->queued($registered));
    }

    public function testWhatOpenImportWritesStartsNothing(): void
    {
        $profile = $this->profile([TriggerEvents::PRODUCT_SAVED]);
        $guard = Bootstrap::getObjectManager()->get(TriggerGuard::class);
        $queue = Bootstrap::getObjectManager()->create(TriggerQueue::class);

        $guard->enter();
        try {
            self::assertSame(0, $queue->fire(TriggerEvents::PRODUCT_SAVED));
        } finally {
            $guard->leave();
        }
        self::assertSame([], $this->queued($profile));
        self::assertSame(1, $queue->fire(TriggerEvents::PRODUCT_SAVED));
        self::assertSame(0, $queue->fire('product_deleted'), 'unknown events start nothing');
    }
}
