<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Schedule;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Api\RunRepositoryInterface;
use Iranimij\OpenImport\Model\Execution\RunFiles;
use Iranimij\OpenImport\Model\Execution\RunProcessor;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Iranimij\OpenImport\Model\Maintenance\Housekeeping;
use Iranimij\OpenImport\Model\ResourceModel\Run as RunResource;
use Iranimij\OpenImport\Model\Schedule\Scheduler;
use Iranimij\OpenImport\Model\Storage\LockManager;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem;
use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Schedules fire once per due minute (also with overlapping or late cron), two processes never run the same profile,
 * and housekeeping removes old files and fails runs whose process died.
 */
#[AppArea('crontab')]
#[DbIsolation(false)]
class SchedulerTest extends TestCase
{
    private function profile(string $schedule, bool $active = true): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Scheduled ' . uniqid())
            ->setEntity('catalog_product')
            ->setSchedule($schedule)
            ->setIsActive($active)
            ->setMapping([['target' => 'sku', 'source' => 'sku']]);

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    /**
     * @param ProfileInterface $profile
     * @return int
     */
    private function runsOf(ProfileInterface $profile): int
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();

        return (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM ' . $connection->getTableName('openimport_run') . ' WHERE profile_id = '
            . (int) $profile->getProfileId()
        );
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    protected function setUp(): void
    {
        // The scheduler reads the store's time zone (store scope), as TimezoneInterface does.
        $config = Bootstrap::getObjectManager()->get(MutableScopeConfigInterface::class);
        $config->setValue('general/locale/timezone', 'Europe/Berlin');
        $config->setValue('general/locale/timezone', 'Europe/Berlin', ScopeInterface::SCOPE_STORE, 'default');
        $config->setValue('general/locale/timezone', 'Europe/Berlin', ScopeInterface::SCOPE_STORE);
    }

    public function testEachDueMinuteFiresOnce(): void
    {
        $scheduler = Bootstrap::getObjectManager()->create(Scheduler::class);
        $nightly = $this->profile('0 3 * * *');
        $inactive = $this->profile('0 3 * * *', false);

        // 03:00 Berlin = 01:00 UTC in October (CEST). Two overlapping cron processes in the same minute.
        $scheduler->queueDue($this->at('2026-10-14 01:00:20'));
        $scheduler->queueDue($this->at('2026-10-14 01:00:50'));
        self::assertSame(1, $this->runsOf($nightly));
        self::assertSame(0, $this->runsOf($inactive));

        // A late cron (5 minutes later) does not fire the same minute again.
        $scheduler->queueDue($this->at('2026-10-14 01:05:00'));
        self::assertSame(1, $this->runsOf($nightly));

        // Next night, cron was down at 03:00 and comes back at 03:07: the missed minute fires once.
        $scheduler->queueDue($this->at('2026-10-15 01:07:00'));
        $scheduler->queueDue($this->at('2026-10-15 01:08:00'));
        self::assertSame(2, $this->runsOf($nightly));

        // Too late (outside the catch-up window): nothing.
        $scheduler->queueDue($this->at('2026-10-16 01:30:00'));
        self::assertSame(2, $this->runsOf($nightly));

        $runs = Bootstrap::getObjectManager()->get(RunRepositoryInterface::class);
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $runId = (int) $connection->fetchOne('SELECT MIN(run_id) FROM ' . $connection->getTableName('openimport_run')
            . ' WHERE profile_id = ' . (int) $nightly->getProfileId());
        $run = $runs->getById($runId);
        self::assertSame(RunInterface::TRIGGER_SCHEDULE, $run->getTrigger());
        self::assertFalse($run->isDryRun());
        self::assertSame('2026-10-14T03:00:00+02:00', $run->getOptions()['scheduled_for']);
    }

    public function testLastDue(): void
    {
        $scheduler = Bootstrap::getObjectManager()->create(Scheduler::class);
        $berlin = new \DateTimeZone('Europe/Berlin');

        self::assertSame(
            '2026-10-14 09:45',
            $scheduler->lastDue('*/15 * * * *', new \DateTimeImmutable('2026-10-14 09:52', $berlin))?->format('Y-m-d H:i')
        );
        self::assertNull($scheduler->lastDue('0 3 * * *', new \DateTimeImmutable('2026-10-14 09:52', $berlin)));
        self::assertNull($scheduler->lastDue('not a schedule', new \DateTimeImmutable('2026-10-14 09:52', $berlin)));
    }

    public function testTwoProcessesNeverRunTheSameProfile(): void
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $table = $connection->getTableName('openimport_run');
        $connection->update($table, ['status' => 'cancelled'], ['status IN (?)' => ['queued', 'running']]);
        $profile = $this->profile('0 3 * * *', false);
        $queue = Bootstrap::getObjectManager()->get(RunQueue::class);
        $first = (int) $queue->queue($profile, RunInterface::MODE_DRY_RUN, RunInterface::TRIGGER_CLI)->getRunId();
        $second = (int) $queue->queue($profile, RunInterface::MODE_DRY_RUN, RunInterface::TRIGGER_CLI)->getRunId();
        // The first run is running in another process (it holds the profile lock).
        Bootstrap::getObjectManager()->get(RunResource::class)->claim($first);
        Bootstrap::getObjectManager()->get(LockManager::class)->acquire('profile:' . $profile->getProfileId(), $first);

        self::assertNull(Bootstrap::getObjectManager()->get(RunProcessor::class)->processNext());
        self::assertSame('queued', Bootstrap::getObjectManager()->get(RunResource::class)->fetchStatus($second));

        // Run directly (CLI) while the lock is held: back to the queue, not run twice.
        $run = Bootstrap::getObjectManager()->get(RunProcessor::class)->process($second);
        self::assertSame(RunInterface::STATUS_QUEUED, $run->getStatus());
        Bootstrap::getObjectManager()->get(LockManager::class)->release('profile:' . $profile->getProfileId(), $first);
    }

    public function testHousekeeping(): void
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();
        $table = $connection->getTableName('openimport_run');
        $profile = $this->profile('0 3 * * *', false);
        $queue = Bootstrap::getObjectManager()->get(RunQueue::class);
        $old = (int) $queue->queue($profile, RunInterface::MODE_DRY_RUN, RunInterface::TRIGGER_CLI)->getRunId();
        $folder = Bootstrap::getObjectManager()->get(RunFiles::class)->folder($old);
        file_put_contents($folder . '/source.csv', "sku\nA\n");
        $connection->update($table, [
            'status' => 'success',
            'finished_at' => '2020-01-01 00:00:00',
            'source_file' => RunFiles::BASE . '/' . $old . '/source.csv',
        ], ['run_id = ?' => $old]);
        $dead = (int) $queue->queue($profile, RunInterface::MODE_DRY_RUN, RunInterface::TRIGGER_CLI)->getRunId();
        $connection->update($table, ['status' => 'running', 'started_at' => '2020-01-01 00:00:00'], ['run_id = ?' => $dead]);

        $result = Bootstrap::getObjectManager()->get(Housekeeping::class)->run();

        self::assertGreaterThanOrEqual(1, $result['run_folders']);
        self::assertFalse(is_dir($folder));
        $runs = Bootstrap::getObjectManager()->get(RunRepositoryInterface::class);
        self::assertNull($runs->getById($old)->getSourceFile());
        self::assertSame(RunInterface::STATUS_FAILED, $runs->getById($dead)->getStatus());
        self::assertStringContainsString('stopped without finishing', $runs->getById($dead)->getMessage());
        self::assertTrue(Bootstrap::getObjectManager()->get(Filesystem::class)
            ->getDirectoryRead(DirectoryList::VAR_DIR)->isExist(RunFiles::BASE));
    }
}
