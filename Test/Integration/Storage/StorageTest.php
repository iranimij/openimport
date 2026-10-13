<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Storage;

use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\Data\RunInterfaceFactory;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Api\RunRepositoryInterface;
use Iranimij\OpenImport\Model\ResourceModel\Run as RunResource;
use Iranimij\OpenImport\Model\Storage\ErrorLog;
use Iranimij\OpenImport\Model\Storage\ErrorRecord;
use Iranimij\OpenImport\Model\Storage\LockManager;
use Iranimij\OpenImport\Model\Storage\PreviewRecord;
use Iranimij\OpenImport\Model\Storage\PreviewStore;
use Iranimij\OpenImport\Model\Storage\RowHashStore;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Run queue, errors, previews, delta hashes and locks against the real schema.
 */
#[AppArea('adminhtml')]
#[DbIsolation(true)]
class StorageTest extends TestCase
{
    private function profileId(): int
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Storage ' . uniqid())
            ->setEntity('customer');

        return (int) Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile)
            ->getProfileId();
    }

    private function queueRun(?int $profileId): int
    {
        $run = Bootstrap::getObjectManager()->get(RunInterfaceFactory::class)->create()
            ->setProfileId($profileId)
            ->setProfileName('x')
            ->setEntity('customer');

        return (int) Bootstrap::getObjectManager()->get(RunRepositoryInterface::class)->save($run)->getRunId();
    }

    public function testRunsAreClaimedOnceInOrder(): void
    {
        $resource = Bootstrap::getObjectManager()->get(RunResource::class);
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $connection->update($resource->getMainTable(), ['status' => 'cancelled'], ['status IN (?)' => ['queued', 'running']]);
        $profileA = $this->profileId();
        $profileB = $this->profileId();
        $first = $this->queueRun($profileA);
        $second = $this->queueRun($profileA);
        $third = $this->queueRun($profileB);

        self::assertSame($first, $resource->claimNextQueued());
        self::assertSame([$profileA], $resource->profilesWithStatus([RunInterface::STATUS_RUNNING]));
        self::assertSame($third, $resource->claimNextQueued([$profileA]));
        self::assertSame($second, $resource->claimNextQueued());
        self::assertNull($resource->claimNextQueued());

        $run = Bootstrap::getObjectManager()->get(RunRepositoryInterface::class)->getById($first);
        self::assertSame(RunInterface::STATUS_RUNNING, $run->getStatus());
        self::assertNotNull($run->getStartedAt());

        $resource->updateProgress($first, 1500, ['read' => 1500, 'errors' => 3, 'bogus' => 9]);
        $run = Bootstrap::getObjectManager()->get(RunRepositoryInterface::class)->getById($first);
        self::assertSame(1500, $run->getProgressRows());
        self::assertSame(1500, $run->getCount('read'));
        self::assertSame(3, $run->getCount('errors'));
        self::assertSame('running', $resource->fetchStatus($first));
        self::assertNull($resource->fetchStatus(999999999));
    }

    public function testRunFields(): void
    {
        $runs = Bootstrap::getObjectManager()->get(RunRepositoryInterface::class);
        $run = Bootstrap::getObjectManager()->get(RunInterfaceFactory::class)->create()
            ->setProfileName('Fields')
            ->setEntity('catalog_product')
            ->setDirection('import')
            ->setTrigger(RunInterface::TRIGGER_SCHEDULE)
            ->setMode(RunInterface::MODE_LIVE)
            ->setOptions(['full' => true])
            ->setMessage('done')
            ->setRequestedBy('admin')
            ->setSourceFile('runs/1/source.csv')
            ->setPeakMemoryMb(120)
            ->setCount('created', 5);
        $loaded = $runs->getById((int) $runs->save($run)->getRunId());

        self::assertSame('schedule', $loaded->getTrigger());
        self::assertFalse($loaded->isDryRun());
        self::assertSame(['full' => true], $loaded->getOptions());
        self::assertSame(5, $loaded->getCounts()['created']);
        self::assertSame(0, $loaded->getCounts()['updated']);
        self::assertSame('runs/1/source.csv', $loaded->getSourceFile());
        self::assertNull($loaded->getOutputFile());
        self::assertSame(120, $loaded->getPeakMemoryMb());
        self::assertNotNull($loaded->getCreatedAt());
    }

    public function testErrorsAndPreview(): void
    {
        $runId = $this->queueRun(null);
        $errors = Bootstrap::getObjectManager()->get(ErrorLog::class);
        $errors->add($runId, [
            new ErrorRecord(18, '"qty" is "12,5".', 'SKU-18', 'qty', ['sku' => 'SKU-18', 'qty' => '12,5']),
            new ErrorRecord(3, 'Bad', 'SKU-3', 'price'),
        ]);

        self::assertSame(2, $errors->count($runId));
        $stored = $errors->fetch($runId);
        self::assertSame([3, 18], [$stored[0]->sourceRow, $stored[1]->sourceRow]);
        self::assertSame(['sku' => 'SKU-18', 'qty' => '12,5'], $stored[1]->sourceData);
        self::assertNull($stored[0]->sourceData);

        $preview = Bootstrap::getObjectManager()->get(PreviewStore::class);
        $preview->add($runId, [
            new PreviewRecord('A', PreviewRecord::UNCHANGED, [], 2),
            new PreviewRecord('B', PreviewRecord::NEW, ['name' => [null, 'Bee']], 3),
            new PreviewRecord('C', PreviewRecord::CHANGED, ['price' => ['10.00', '11.50']], 4),
        ]);
        $records = $preview->fetch($runId);
        self::assertSame(['C', 'B', 'A'], array_map(static fn (PreviewRecord $record): string => $record->identifier, $records));
        self::assertSame(['price' => ['10.00', '11.50']], $records[0]->diff);
        self::assertSame(['name' => [null, 'Bee']], $records[1]->diff);
    }

    public function testRowHashes(): void
    {
        $profileId = $this->profileId();
        $store = Bootstrap::getObjectManager()->get(RowHashStore::class);
        $store->save($profileId, ['A' => 'h1', 'B' => 'h2', 'Ä' => 'h3']);
        $store->save($profileId, ['B' => 'h2b']);

        self::assertSame(['A' => 'h1', 'B' => 'h2b'], $store->fetch($profileId, ['A', 'B', 'Z']));
        self::assertSame(['Ä' => 'h3'], $store->fetch($profileId, ['Ä']));
        $store->delete($profileId, ['A']);
        self::assertSame(2, $store->count($profileId));

        $sep = RowHashStore::SEPARATOR;
        $store->save($profileId, ['A_1' => 'x', "A_1{$sep}de{$sep}0" => 'y', "A_1{$sep}{$sep}1" => 'z', "A%1{$sep}de{$sep}0" => 'w']);
        $store->delete($profileId, ['A_1']);
        self::assertSame(["A%1{$sep}de{$sep}0" => 'w'], $store->fetch($profileId, ['A_1', "A_1{$sep}de{$sep}0", "A%1{$sep}de{$sep}0"]));
        $store->clear($profileId);
        self::assertSame(0, $store->count($profileId));
    }

    public function testLocks(): void
    {
        $locks = Bootstrap::getObjectManager()->create(LockManager::class, ['staleSeconds' => 60]);
        $name = 'profile:test-' . uniqid();

        self::assertTrue($locks->acquire($name, 1));
        self::assertTrue($locks->acquire($name, 1));
        self::assertFalse($locks->acquire($name, 2));
        self::assertSame(1, $locks->holder($name));
        $locks->heartbeat($name, 1);
        $locks->release($name, 2);
        self::assertSame(1, $locks->holder($name));
        $locks->release($name, 1);
        self::assertNull($locks->holder($name));
        self::assertTrue($locks->acquire($name, 2));

        // A lock whose heartbeat is older than the stale time is taken over.
        Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection()->update(
            Bootstrap::getObjectManager()->get(ResourceConnection::class)->getTableName('openimport_lock'),
            ['heartbeat_at' => gmdate('Y-m-d H:i:s', time() - 3600)],
            ['lock_name = ?' => $name]
        );
        self::assertNull($locks->holder($name));
        self::assertTrue($locks->acquire($name, 3));
        self::assertSame(3, $locks->holder($name));
    }
}
