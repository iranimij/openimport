<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Schedule;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Iranimij\OpenImport\Model\ResourceModel\Profile\CollectionFactory;
use Iranimij\OpenImport\Model\Storage\LockManager;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Psr\Log\LoggerInterface;

/**
 * Queues the runs of active profiles whose schedule is due. Schedules are read in the store's configured time zone.
 *
 * Each due minute fires once, even when cron runs overlap or come late: the first process that records the
 * "schedule:<profile>:<minute>" lock queues the run; a minute missed by up to the catch-up window still fires, once.
 */
class Scheduler
{
    private const CATCH_UP_MINUTES = 10;

    /**
     * @param CollectionFactory $profiles
     * @param RunQueue $queue
     * @param LockManager $locks
     * @param TimezoneInterface $timezone
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CollectionFactory $profiles,
        private readonly RunQueue $queue,
        private readonly LockManager $locks,
        private readonly TimezoneInterface $timezone,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param \DateTimeImmutable|null $now
     * @return RunInterface[] the runs queued now
     */
    public function queueDue(?\DateTimeImmutable $now = null): array
    {
        $zone = new \DateTimeZone($this->timezone->getConfigTimezone() ?: 'UTC');
        $now = ($now ?? new \DateTimeImmutable('now'))->setTimezone($zone);
        $collection = $this->profiles->create()
            ->addFieldToFilter(ProfileInterface::IS_ACTIVE, 1)
            ->addFieldToFilter(ProfileInterface::SCHEDULE, ['notnull' => true]);
        $queued = [];
        foreach ($collection as $profile) {
            /** @var ProfileInterface $profile */
            $schedule = trim((string) $profile->getSchedule());
            $due = $schedule === '' ? null : $this->lastDue($schedule, $now);
            if ($due === null) {
                continue;
            }
            $minute = $due->setTimezone(new \DateTimeZone('UTC'))->format('YmdHi');
            if (!$this->locks->claimOnce(sprintf('schedule:%d:%s', (int) $profile->getProfileId(), $minute))) {
                continue;
            }
            $queued[] = $this->queue->queue(
                $profile,
                RunInterface::MODE_LIVE,
                RunInterface::TRIGGER_SCHEDULE,
                ['scheduled_for' => $due->format(\DATE_ATOM)],
                'schedule'
            );
        }

        return $queued;
    }

    /**
     * The latest minute within the catch-up window at which the schedule was due, or null.
     *
     * @param string $schedule
     * @param \DateTimeImmutable $now
     * @return \DateTimeImmutable|null
     */
    public function lastDue(string $schedule, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        try {
            $cron = new CronExpression($schedule);
        } catch (\InvalidArgumentException $exception) {
            $this->logger->warning(sprintf('OpenImport: schedule "%s" is not valid: %s', $schedule, $exception->getMessage()));
            return null;
        }
        $minute = $now->setTime((int) $now->format('G'), (int) $now->format('i'));
        for ($i = 0; $i < self::CATCH_UP_MINUTES; $i++) {
            $candidate = $minute->modify(sprintf('-%d minutes', $i));
            if ($cron->matches($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
