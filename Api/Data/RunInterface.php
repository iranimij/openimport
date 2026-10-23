<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Api\Data;

/**
 * One run of a profile: queued, running and finished, with its counts.
 *
 * @api
 */
interface RunInterface
{
    public const RUN_ID = 'run_id';
    public const PROFILE_ID = 'profile_id';
    public const PROFILE_NAME = 'profile_name';
    public const DIRECTION = 'direction';
    public const ENTITY = 'entity';
    public const TRIGGER = 'run_trigger';
    public const MODE = 'mode';
    public const STATUS = 'status';
    public const SOURCE_FILE = 'source_file';
    public const OUTPUT_FILE = 'output_file';
    public const OPTIONS = 'options';
    public const MESSAGE = 'message';
    public const REQUESTED_BY = 'requested_by';
    public const PARENT_RUN_ID = 'parent_run_id';
    public const PROGRESS_ROWS = 'progress_rows';
    public const PEAK_MEMORY_MB = 'peak_memory_mb';
    public const CREATED_AT = 'created_at';
    public const STARTED_AT = 'started_at';
    public const FINISHED_AT = 'finished_at';

    public const COUNTS = ['read', 'filtered', 'unchanged', 'skipped', 'invalid', 'created', 'updated', 'deleted',
        'errors', 'exported'];

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';
    public const STATUS_NEEDS_CONFIRMATION = 'needs_confirmation';
    public const STATUS_CANCELLED = 'cancelled';
    public const FINAL_STATUSES = [self::STATUS_SUCCESS, self::STATUS_PARTIAL, self::STATUS_FAILED,
        self::STATUS_NEEDS_CONFIRMATION, self::STATUS_CANCELLED];

    public const MODE_DRY_RUN = 'dry_run';
    public const MODE_LIVE = 'live';

    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_SCHEDULE = 'schedule';
    public const TRIGGER_CLI = 'cli';
    public const TRIGGER_API = 'api';
    public const TRIGGER_EVENT = 'event';
    public const TRIGGER_CHAIN = 'chain';

    /**
     * @return int|null
     */
    public function getRunId(): ?int;

    /**
     * @return int|null null when the profile was deleted
     */
    public function getProfileId(): ?int;

    /**
     * @param int|null $profileId
     * @return $this
     */
    public function setProfileId(?int $profileId): self;

    /**
     * @return string
     */
    public function getProfileName(): string;

    /**
     * @param string $name
     * @return $this
     */
    public function setProfileName(string $name): self;

    /**
     * @return string
     */
    public function getDirection(): string;

    /**
     * @param string $direction
     * @return $this
     */
    public function setDirection(string $direction): self;

    /**
     * @return string
     */
    public function getEntity(): string;

    /**
     * @param string $entity
     * @return $this
     */
    public function setEntity(string $entity): self;

    /**
     * @return string manual, schedule, cli or api
     */
    public function getTrigger(): string;

    /**
     * @param string $trigger
     * @return $this
     */
    public function setTrigger(string $trigger): self;

    /**
     * @return string dry_run or live
     */
    public function getMode(): string;

    /**
     * @param string $mode
     * @return $this
     */
    public function setMode(string $mode): self;

    /**
     * @return bool
     */
    public function isDryRun(): bool;

    /**
     * @return string
     */
    public function getStatus(): string;

    /**
     * @param string $status
     * @return $this
     */
    public function setStatus(string $status): self;

    /**
     * @param string $name one of self::COUNTS
     * @return int
     */
    public function getCount(string $name): int;

    /**
     * @param string $name one of self::COUNTS
     * @param int $value
     * @return $this
     */
    public function setCount(string $name, int $value): self;

    /**
     * @return array<string, int> all counts
     */
    public function getCounts(): array;

    /**
     * @return string|null
     */
    public function getSourceFile(): ?string;

    /**
     * @param string|null $file
     * @return $this
     */
    public function setSourceFile(?string $file): self;

    /**
     * @return string|null
     */
    public function getOutputFile(): ?string;

    /**
     * @param string|null $file
     * @return $this
     */
    public function setOutputFile(?string $file): self;

    /**
     * @return array<string, mixed> full (ignore delta), file override, confirmed …
     */
    public function getOptions(): array;

    /**
     * @param array<string, mixed> $options
     * @return $this
     */
    public function setOptions(array $options): self;

    /**
     * @return string
     */
    public function getMessage(): string;

    /**
     * @param string $message
     * @return $this
     */
    public function setMessage(string $message): self;

    /**
     * @return string|null
     */
    public function getRequestedBy(): ?string;

    /**
     * @param string|null $requestedBy
     * @return $this
     */
    public function setRequestedBy(?string $requestedBy): self;

    /**
     * @return int|null
     */
    public function getParentRunId(): ?int;

    /**
     * @param int|null $runId
     * @return $this
     */
    public function setParentRunId(?int $runId): self;

    /**
     * @return int
     */
    public function getProgressRows(): int;

    /**
     * @return int
     */
    public function getPeakMemoryMb(): int;

    /**
     * @param int $megabytes
     * @return $this
     */
    public function setPeakMemoryMb(int $megabytes): self;

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * @return string|null
     */
    public function getStartedAt(): ?string;

    /**
     * @param string|null $time
     * @return $this
     */
    public function setStartedAt(?string $time): self;

    /**
     * @return string|null
     */
    public function getFinishedAt(): ?string;

    /**
     * @param string|null $time
     * @return $this
     */
    public function setFinishedAt(?string $time): self;
}
