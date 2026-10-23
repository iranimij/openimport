<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Api\Data;

/**
 * An import or export profile: source, format, mapping, rules and schedule.
 *
 * @api
 */
interface ProfileInterface
{
    public const PROFILE_ID = 'profile_id';
    public const NAME = 'name';
    public const DIRECTION = 'direction';
    public const ENTITY = 'entity';
    public const IS_ACTIVE = 'is_active';
    public const SOURCE_TYPE = 'source_type';
    public const SOURCE_CONFIG = 'source_config';
    public const FORMAT = 'format';
    public const FORMAT_OPTIONS = 'format_options';
    public const BEHAVIOR = 'behavior';
    public const ON_ERROR = 'on_error';
    public const ALLOWED_ERRORS = 'allowed_errors';
    public const DELTA = 'delta';
    public const SAFETY_THRESHOLD = 'safety_threshold';
    public const SCHEDULE = 'schedule';
    public const AFTER_SUCCESS = 'after_success';
    public const NOTIFY = 'notify';
    public const NOTIFY_EMAILS = 'notify_emails';
    public const ROW_FILTERS = 'row_filters';
    public const EXPORT_OPTIONS = 'export_options';
    public const IMPORT_OPTIONS = 'import_options';
    public const THEN_PROFILE_ID = 'then_profile_id';
    public const THEN_WHEN = 'then_when';

    public const TRIGGERS = 'triggers';
    public const TRIGGER_EVENTS = 'events';
    public const TRIGGER_ORDER_STATUSES = 'order_statuses';

    public const THEN_SUCCESS = 'success';
    public const THEN_ALWAYS = 'always';
    public const CREATED_BY = 'created_by';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';
    public const MAPPING = 'mapping';
    public const VALUE_MAPS = 'value_maps';

    public const DIRECTION_IMPORT = 'import';
    public const DIRECTION_EXPORT = 'export';

    public const BEHAVIOR_ADD_UPDATE = 'add_update';
    public const BEHAVIOR_ONLY_ADD = 'only_add';
    public const BEHAVIOR_ONLY_UPDATE = 'only_update';
    public const BEHAVIOR_REPLACE = 'replace';
    public const BEHAVIOR_DELETE = 'delete';
    public const BEHAVIORS = [self::BEHAVIOR_ADD_UPDATE, self::BEHAVIOR_ONLY_ADD, self::BEHAVIOR_ONLY_UPDATE,
        self::BEHAVIOR_REPLACE, self::BEHAVIOR_DELETE];

    public const SOURCE_TYPES = ['upload', 'folder', 'url', 'sftp', 'ftp', 's3', 'google_drive', 'dropbox',
        'onedrive', 'rest'];
    public const FORMATS = ['auto', 'csv', 'xlsx', 'ods', 'json', 'xml'];
    public const ON_ERROR_STOP = 'stop';
    public const ON_ERROR_SKIP = 'skip';
    public const AFTER_SUCCESS_OPTIONS = ['keep', 'move', 'delete'];
    public const NOTIFY_OPTIONS = ['never', 'failure', 'always'];

    /**
     * @return int|null
     */
    public function getProfileId(): ?int;

    /**
     * @return string
     */
    public function getName(): string;

    /**
     * @param string $name
     * @return $this
     */
    public function setName(string $name): self;

    /**
     * @return string import or export
     */
    public function getDirection(): string;

    /**
     * @param string $direction
     * @return $this
     */
    public function setDirection(string $direction): self;

    /**
     * @return string entity code
     */
    public function getEntity(): string;

    /**
     * @param string $entity
     * @return $this
     */
    public function setEntity(string $entity): self;

    /**
     * @return bool the schedule runs
     */
    public function isActive(): bool;

    /**
     * @param bool $active
     * @return $this
     */
    public function setIsActive(bool $active): self;

    /**
     * @return string
     */
    public function getSourceType(): string;

    /**
     * @param string $type
     * @return $this
     */
    public function setSourceType(string $type): self;

    /**
     * @return array<string, mixed> source or destination settings; secrets are encrypted
     */
    public function getSourceConfig(): array;

    /**
     * @param array<string, mixed> $config
     * @return $this
     */
    public function setSourceConfig(array $config): self;

    /**
     * @return string
     */
    public function getFormat(): string;

    /**
     * @param string $format
     * @return $this
     */
    public function setFormat(string $format): self;

    /**
     * @return array<string, mixed>
     */
    public function getFormatOptions(): array;

    /**
     * @param array<string, mixed> $options
     * @return $this
     */
    public function setFormatOptions(array $options): self;

    /**
     * @return string
     */
    public function getBehavior(): string;

    /**
     * @param string $behavior
     * @return $this
     */
    public function setBehavior(string $behavior): self;

    /**
     * @return string stop or skip
     */
    public function getOnError(): string;

    /**
     * @param string $onError
     * @return $this
     */
    public function setOnError(string $onError): self;

    /**
     * @return int
     */
    public function getAllowedErrors(): int;

    /**
     * @param int $allowed
     * @return $this
     */
    public function setAllowedErrors(int $allowed): self;

    /**
     * @return bool skip unchanged rows
     */
    public function isDelta(): bool;

    /**
     * @param bool $delta
     * @return $this
     */
    public function setDelta(bool $delta): self;

    /**
     * @return int|null percent, null = configuration default
     */
    public function getSafetyThreshold(): ?int;

    /**
     * @param int|null $percent
     * @return $this
     */
    public function setSafetyThreshold(?int $percent): self;

    /**
     * @return string|null cron expression, null = manual only
     */
    public function getSchedule(): ?string;

    /**
     * @param string|null $schedule
     * @return $this
     */
    public function setSchedule(?string $schedule): self;

    /**
     * @return string keep, move or delete
     */
    public function getAfterSuccess(): string;

    /**
     * @param string $afterSuccess
     * @return $this
     */
    public function setAfterSuccess(string $afterSuccess): self;

    /**
     * @return string never, failure or always
     */
    public function getNotify(): string;

    /**
     * @param string $notify
     * @return $this
     */
    public function setNotify(string $notify): self;

    /**
     * @return string comma-separated addresses
     */
    public function getNotifyEmails(): string;

    /**
     * @param string $emails
     * @return $this
     */
    public function setNotifyEmails(string $emails): self;

    /**
     * @return array<string, mixed>
     */
    public function getRowFilters(): array;

    /**
     * @param array<string, mixed> $filters
     * @return $this
     */
    public function setRowFilters(array $filters): self;

    /**
     * @return array<string, mixed>
     */
    public function getExportOptions(): array;

    /**
     * @param array<string, mixed> $options
     * @return $this
     */
    public function setExportOptions(array $options): self;

    /**
     * Import run options that are not columns of their own (images_on_error: error | skip).
     *
     * @return array<string, mixed>
     */
    public function getImportOptions(): array;

    /**
     * @param array<string, mixed> $options
     * @return $this
     */
    public function setImportOptions(array $options): self;

    /**
     * The profile that runs after this one (job chain), null for none.
     *
     * @return int|null
     */
    public function getThenProfileId(): ?int;

    /**
     * @param int|null $profileId
     * @return $this
     */
    public function setThenProfileId(?int $profileId): self;

    /**
     * When the next profile runs: THEN_SUCCESS (after a successful or partial run) or THEN_ALWAYS.
     *
     * @return string
     */
    public function getThenWhen(): string;

    /**
     * @param string $when
     * @return $this
     */
    public function setThenWhen(string $when): self;

    /**
     * Shop events after which the profile runs (while it is switched on): ['events' => TriggerEvents constants,
     * 'order_statuses' => statuses for "an order changes status", none = every status].
     *
     * @return array{events: string[], order_statuses: string[]}
     */
    public function getTriggers(): array;

    /**
     * @param array<string, mixed> $triggers
     * @return $this
     */
    public function setTriggers(array $triggers): self;

    /**
     * Mapping rows in order: target, source, static, transforms, required, value_map_case_sensitive,
     * value_map_strict.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMapping(): array;

    /**
     * @param array<int, array<string, mixed>> $mapping
     * @return $this
     */
    public function setMapping(array $mapping): self;

    /**
     * Value maps per target field: target => [from => to].
     *
     * @return array<string, array<string, string>>
     */
    public function getValueMaps(): array;

    /**
     * @param array<string, array<string, string>> $valueMaps
     * @return $this
     */
    public function setValueMaps(array $valueMaps): self;

    /**
     * @return string|null
     */
    public function getCreatedBy(): ?string;

    /**
     * @param string|null $user
     * @return $this
     */
    public function setCreatedBy(?string $user): self;

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * @return string|null
     */
    public function getUpdatedAt(): ?string;
}
