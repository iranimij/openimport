<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Schedule;

use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Iranimij\OpenImport\Model\ResourceModel\Profile\Collection;
use Iranimij\OpenImport\Model\ResourceModel\Profile\CollectionFactory;
use Iranimij\OpenImport\Model\Schedule\Scheduler;
use Iranimij\OpenImport\Model\Storage\LockManager;
use Iranimij\OpenImport\Test\Unit\Model\ProfileStub;
use Iranimij\OpenImport\Test\Unit\Model\RunStub;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SchedulerTest extends TestCase
{
    /** @var string[] lock names claimed so far */
    private array $claimed = [];

    /** @var array<int, array<string, mixed>> */
    private array $queued = [];

    /**
     * @param ProfileStub[] $profiles
     * @param LoggerInterface|null $logger
     * @return Scheduler
     */
    private function scheduler(array $profiles = [], ?LoggerInterface $logger = null): Scheduler
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($profiles));
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $locks = $this->createMock(LockManager::class);
        $locks->method('claimOnce')->willReturnCallback(function (string $name): bool {
            if (in_array($name, $this->claimed, true)) {
                return false;
            }
            $this->claimed[] = $name;

            return true;
        });

        $queue = $this->createMock(RunQueue::class);
        $queue->method('queue')->willReturnCallback(
            function ($profile, string $mode, string $trigger, array $options, ?string $by): RunInterface {
                $this->queued[] = ['profile' => $profile->getProfileId(), 'mode' => $mode, 'trigger' => $trigger,
                    'options' => $options, 'by' => $by];

                return new RunStub(['profile_id' => $profile->getProfileId()]);
            }
        );

        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturn('Europe/Berlin');

        return new Scheduler($factory, $queue, $locks, $timezone, $logger ?? $this->createMock(LoggerInterface::class));
    }

    private function berlin(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('Europe/Berlin'));
    }

    public function testLastDueIsTheMatchingMinuteWithinTheCatchUpWindow(): void
    {
        $scheduler = $this->scheduler();

        self::assertSame(
            '2026-10-13 02:00',
            $scheduler->lastDue('0 2 * * *', $this->berlin('2026-10-13 02:00:42'))?->format('Y-m-d H:i')
        );
        self::assertSame(
            '2026-10-13 02:00',
            $scheduler->lastDue('0 2 * * *', $this->berlin('2026-10-13 02:09'))?->format('Y-m-d H:i')
        );
        self::assertNull($scheduler->lastDue('0 2 * * *', $this->berlin('2026-10-13 02:10')));
        self::assertSame(
            '2026-10-13 10:15',
            $scheduler->lastDue('*/15 * * * *', $this->berlin('2026-10-13 10:17'))?->format('Y-m-d H:i')
        );
    }

    public function testAnInvalidScheduleIsLoggedAndSkipped(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $scheduler = $this->scheduler([], $logger);

        self::assertNull($scheduler->lastDue('99 * * * *', $this->berlin('2026-10-13 10:00')));
    }

    public function testEachDueMinuteIsQueuedOnce(): void
    {
        $profiles = [
            new ProfileStub(['profile_id' => 3, 'name' => 'Nightly stock', 'schedule' => '0 2 * * *']),
            new ProfileStub(['profile_id' => 4, 'name' => 'Hourly prices', 'schedule' => '0 * * * *']),
        ];
        $scheduler = $this->scheduler($profiles);

        self::assertCount(2, $scheduler->queueDue($this->berlin('2026-10-13 02:00:05')));
        self::assertCount(0, $scheduler->queueDue($this->berlin('2026-10-13 02:01')), 'a later tick in the window');
        self::assertCount(0, $scheduler->queueDue($this->berlin('2026-10-13 02:30')));
        self::assertCount(1, $scheduler->queueDue($this->berlin('2026-10-13 03:04')), 'a late cron still fires');

        self::assertSame(['schedule:3:202610130000', 'schedule:4:202610130000', 'schedule:4:202610130100'], $this->claimed);
        self::assertSame(
            ['profile' => 3, 'mode' => RunInterface::MODE_LIVE, 'trigger' => RunInterface::TRIGGER_SCHEDULE,
                'options' => ['scheduled_for' => '2026-10-13T02:00:00+02:00'], 'by' => 'schedule'],
            $this->queued[0]
        );
    }
}
