<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Trigger;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Entity\EntityRegistry;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Iranimij\OpenImport\Model\JsonField;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Queues a live run of every switched-on profile that runs after a shop event. A profile with a run already waiting
 * gets no second one, so 1,000 product saves in a minute make one run, which cron starts within the minute. Events
 * raised by OpenImport's own runs start nothing (see TriggerGuard). Never throws: a shop save must not fail because
 * of an export.
 */
class TriggerQueue
{
    /**
     * A profile queued in this process is not checked again for this many seconds (mass saves).
     */
    private const QUIET_SECONDS = 30;

    /**
     * @var array<int, int> profile id => time it was queued (or found waiting) in this process
     */
    private array $recent = [];

    /**
     * @param ResourceConnection $resource
     * @param JsonField $json
     * @param ProfileRepositoryInterface $profiles
     * @param RunQueue $queue
     * @param TriggerGuard $guard
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly JsonField $json,
        private readonly ProfileRepositoryInterface $profiles,
        private readonly RunQueue $queue,
        private readonly TriggerGuard $guard,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param string $event a TriggerEvents constant
     * @param array<string, mixed> $context 'status' for an order status change
     * @return int the number of runs queued
     */
    public function fire(string $event, array $context = []): int
    {
        if ($this->guard->inRun() || !isset(TriggerEvents::LABELS[$event])) {
            return 0;
        }
        $queued = 0;
        try {
            foreach ($this->profileIds($event, $context) as $profileId) {
                if (isset($this->recent[$profileId]) && time() - $this->recent[$profileId] < self::QUIET_SECONDS) {
                    continue;
                }
                $this->recent[$profileId] = time();
                if ($this->waiting($profileId)) {
                    continue;
                }
                $this->queue->queue(
                    $this->profiles->getById($profileId),
                    RunInterface::MODE_LIVE,
                    RunInterface::TRIGGER_EVENT,
                    [],
                    TriggerEvents::SHORT[$event]
                );
                $queued++;
            }
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf('OpenImport: could not queue runs after "%s": %s', $event, $exception->getMessage()));
        }

        return $queued;
    }

    /**
     * Profiles that run after this event: switched on, the event chosen, for a status change one of the chosen
     * statuses (none chosen = every status). Order exports with the older "Also run after every order" switch run
     * after a placed order as before.
     *
     * @param string $event
     * @param array<string, mixed> $context
     * @return int[]
     */
    private function profileIds(string $event, array $context): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('openimport_profile');
        $chosen = $connection->quoteInto('triggers LIKE ?', '%"' . $event . '"%');
        $where = '(' . $chosen . ' AND is_active = 1)';
        if ($event === TriggerEvents::ORDER_PLACED) {
            $where .= ' OR (' . $connection->quoteInto('direction = ?', ProfileInterface::DIRECTION_EXPORT)
                . ' AND ' . $connection->quoteInto('entity = ?', EntityRegistry::ORDER)
                . ' AND ' . $connection->quoteInto('export_options LIKE ?', '%"on_place"%') . ')';
        }
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['profile_id', 'triggers', 'is_active', 'export_options'])
                ->where($where)
                ->order('profile_id')
        );
        $ids = [];
        foreach ($rows as $row) {
            $triggers = $this->json->decode($row['triggers']);
            $events = array_map('strval', (array) ($triggers[ProfileInterface::TRIGGER_EVENTS] ?? []));
            $wanted = (int) $row['is_active'] === 1 && in_array($event, $events, true);
            if (!$wanted && $event === TriggerEvents::ORDER_PLACED) {
                // Saved by the form as "1"; by JSON files, the CLI or the API also as 1 or true.
                $wanted = in_array(
                    $this->json->decode($row['export_options'])['order']['on_place'] ?? null,
                    ['1', 1, true, 'true'],
                    true
                );
            }
            if ($wanted && $event === TriggerEvents::ORDER_STATUS_CHANGED) {
                $statuses = array_map('strval', (array) ($triggers[ProfileInterface::TRIGGER_ORDER_STATUSES] ?? []));
                $wanted = $statuses === [] || in_array((string) ($context['status'] ?? ''), $statuses, true);
            }
            if ($wanted) {
                $ids[] = (int) $row['profile_id'];
            }
        }

        return $ids;
    }

    /**
     * @param int $profileId
     * @return bool whether a run of the profile is queued already
     */
    private function waiting(int $profileId): bool
    {
        $connection = $this->resource->getConnection();

        return (bool) $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('openimport_run'), ['run_id'])
                ->where('profile_id = ?', $profileId)
                ->where('status = ?', RunInterface::STATUS_QUEUED)
                ->limit(1)
        );
    }
}
