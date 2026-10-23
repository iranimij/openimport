<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\ResourceModel\Profile as ProfileResource;
use Magento\Framework\Model\AbstractModel;

/**
 * Profile model. JSON columns are kept as text in the data array and decoded by the getters; mapping rows and value
 * maps are loaded and saved by the resource model.
 */
class Profile extends AbstractModel implements ProfileInterface
{
    /**
     * @var string
     */
    protected $_eventPrefix = 'openimport_profile';

    private ?JsonField $json = null;

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(ProfileResource::class);
    }

    private function json(): JsonField
    {
        return $this->json ??= new JsonField();
    }

    /**
     * @inheritDoc
     */
    public function getProfileId(): ?int
    {
        $id = $this->getData(self::PROFILE_ID);

        return $id === null || $id === '' ? null : (int) $id;
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return (string) $this->getData(self::NAME);
    }

    /**
     * @inheritDoc
     */
    public function setName(string $name): ProfileInterface
    {
        return $this->setData(self::NAME, $name);
    }

    /**
     * @inheritDoc
     */
    public function getDirection(): string
    {
        return (string) ($this->getData(self::DIRECTION) ?: self::DIRECTION_IMPORT);
    }

    /**
     * @inheritDoc
     */
    public function setDirection(string $direction): ProfileInterface
    {
        return $this->setData(self::DIRECTION, $direction);
    }

    /**
     * @inheritDoc
     */
    public function getEntity(): string
    {
        return (string) $this->getData(self::ENTITY);
    }

    /**
     * @inheritDoc
     */
    public function setEntity(string $entity): ProfileInterface
    {
        return $this->setData(self::ENTITY, $entity);
    }

    /**
     * @inheritDoc
     */
    public function isActive(): bool
    {
        return (bool) $this->getData(self::IS_ACTIVE);
    }

    /**
     * @inheritDoc
     */
    public function setIsActive(bool $active): ProfileInterface
    {
        return $this->setData(self::IS_ACTIVE, (int) $active);
    }

    /**
     * @inheritDoc
     */
    public function getSourceType(): string
    {
        return (string) ($this->getData(self::SOURCE_TYPE) ?: 'upload');
    }

    /**
     * @inheritDoc
     */
    public function setSourceType(string $type): ProfileInterface
    {
        return $this->setData(self::SOURCE_TYPE, $type);
    }

    /**
     * @inheritDoc
     */
    public function getSourceConfig(): array
    {
        return $this->json()->decode($this->getData(self::SOURCE_CONFIG));
    }

    /**
     * @inheritDoc
     */
    public function setSourceConfig(array $config): ProfileInterface
    {
        return $this->setData(self::SOURCE_CONFIG, $this->json()->encode($config));
    }

    /**
     * @inheritDoc
     */
    public function getFormat(): string
    {
        return (string) ($this->getData(self::FORMAT) ?: 'auto');
    }

    /**
     * @inheritDoc
     */
    public function setFormat(string $format): ProfileInterface
    {
        return $this->setData(self::FORMAT, $format);
    }

    /**
     * @inheritDoc
     */
    public function getFormatOptions(): array
    {
        return $this->json()->decode($this->getData(self::FORMAT_OPTIONS));
    }

    /**
     * @inheritDoc
     */
    public function setFormatOptions(array $options): ProfileInterface
    {
        return $this->setData(self::FORMAT_OPTIONS, $this->json()->encode($options));
    }

    /**
     * @inheritDoc
     */
    public function getBehavior(): string
    {
        return (string) ($this->getData(self::BEHAVIOR) ?: self::BEHAVIOR_ADD_UPDATE);
    }

    /**
     * @inheritDoc
     */
    public function setBehavior(string $behavior): ProfileInterface
    {
        return $this->setData(self::BEHAVIOR, $behavior);
    }

    /**
     * @inheritDoc
     */
    public function getOnError(): string
    {
        return (string) ($this->getData(self::ON_ERROR) ?: self::ON_ERROR_STOP);
    }

    /**
     * @inheritDoc
     */
    public function setOnError(string $onError): ProfileInterface
    {
        return $this->setData(self::ON_ERROR, $onError);
    }

    /**
     * @inheritDoc
     */
    public function getAllowedErrors(): int
    {
        return (int) $this->getData(self::ALLOWED_ERRORS);
    }

    /**
     * @inheritDoc
     */
    public function setAllowedErrors(int $allowed): ProfileInterface
    {
        return $this->setData(self::ALLOWED_ERRORS, max(0, $allowed));
    }

    /**
     * @inheritDoc
     */
    public function isDelta(): bool
    {
        return (bool) $this->getData(self::DELTA);
    }

    /**
     * @inheritDoc
     */
    public function setDelta(bool $delta): ProfileInterface
    {
        return $this->setData(self::DELTA, (int) $delta);
    }

    /**
     * @inheritDoc
     */
    public function getSafetyThreshold(): ?int
    {
        $value = $this->getData(self::SAFETY_THRESHOLD);

        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * @inheritDoc
     */
    public function setSafetyThreshold(?int $percent): ProfileInterface
    {
        return $this->setData(self::SAFETY_THRESHOLD, $percent);
    }

    /**
     * @inheritDoc
     */
    public function getSchedule(): ?string
    {
        $value = trim((string) $this->getData(self::SCHEDULE));

        return $value === '' ? null : $value;
    }

    /**
     * @inheritDoc
     */
    public function setSchedule(?string $schedule): ProfileInterface
    {
        return $this->setData(self::SCHEDULE, $schedule === null || trim($schedule) === '' ? null : trim($schedule));
    }

    /**
     * @inheritDoc
     */
    public function getAfterSuccess(): string
    {
        return (string) ($this->getData(self::AFTER_SUCCESS) ?: 'keep');
    }

    /**
     * @inheritDoc
     */
    public function setAfterSuccess(string $afterSuccess): ProfileInterface
    {
        return $this->setData(self::AFTER_SUCCESS, $afterSuccess);
    }

    /**
     * @inheritDoc
     */
    public function getNotify(): string
    {
        return (string) ($this->getData(self::NOTIFY) ?: 'failure');
    }

    /**
     * @inheritDoc
     */
    public function setNotify(string $notify): ProfileInterface
    {
        return $this->setData(self::NOTIFY, $notify);
    }

    /**
     * @inheritDoc
     */
    public function getNotifyEmails(): string
    {
        return (string) $this->getData(self::NOTIFY_EMAILS);
    }

    /**
     * @inheritDoc
     */
    public function setNotifyEmails(string $emails): ProfileInterface
    {
        return $this->setData(self::NOTIFY_EMAILS, $emails);
    }

    /**
     * @inheritDoc
     */
    public function getRowFilters(): array
    {
        return $this->json()->decode($this->getData(self::ROW_FILTERS));
    }

    /**
     * @inheritDoc
     */
    public function setRowFilters(array $filters): ProfileInterface
    {
        return $this->setData(self::ROW_FILTERS, $this->json()->encode($filters));
    }

    /**
     * @inheritDoc
     */
    public function getExportOptions(): array
    {
        return $this->json()->decode($this->getData(self::EXPORT_OPTIONS));
    }

    /**
     * @inheritDoc
     */
    public function setExportOptions(array $options): ProfileInterface
    {
        return $this->setData(self::EXPORT_OPTIONS, $this->json()->encode($options));
    }

    /**
     * @inheritDoc
     */
    public function getImportOptions(): array
    {
        return $this->json()->decode($this->getData(self::IMPORT_OPTIONS));
    }

    /**
     * @inheritDoc
     */
    public function setImportOptions(array $options): ProfileInterface
    {
        return $this->setData(self::IMPORT_OPTIONS, $this->json()->encode($options));
    }

    /**
     * @inheritDoc
     */
    public function getThenProfileId(): ?int
    {
        $id = $this->getData(self::THEN_PROFILE_ID);

        return $id === null || $id === '' || (int) $id === 0 ? null : (int) $id;
    }

    /**
     * @inheritDoc
     */
    public function setThenProfileId(?int $profileId): ProfileInterface
    {
        return $this->setData(self::THEN_PROFILE_ID, $profileId === null || $profileId === 0 ? null : $profileId);
    }

    /**
     * @inheritDoc
     */
    public function getThenWhen(): string
    {
        $when = (string) $this->getData(self::THEN_WHEN);

        return $when === self::THEN_ALWAYS ? self::THEN_ALWAYS : self::THEN_SUCCESS;
    }

    /**
     * @inheritDoc
     */
    public function setThenWhen(string $when): ProfileInterface
    {
        return $this->setData(self::THEN_WHEN, $when === self::THEN_ALWAYS ? self::THEN_ALWAYS : self::THEN_SUCCESS);
    }

    /**
     * @inheritDoc
     */
    public function getTriggers(): array
    {
        return $this->cleanTriggers($this->json()->decode($this->getData(self::TRIGGERS)));
    }

    /**
     * @inheritDoc
     */
    public function setTriggers(array $triggers): ProfileInterface
    {
        $clean = $this->cleanTriggers($triggers);

        return $this->setData(
            self::TRIGGERS,
            $clean[self::TRIGGER_EVENTS] === [] ? null : $this->json()->encode($clean)
        );
    }

    /**
     * @param array<mixed> $triggers
     * @return array{events: string[], order_statuses: string[]}
     */
    private function cleanTriggers(array $triggers): array
    {
        $list = static fn (mixed $values): array => array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '', (array) $values),
            static fn (string $value): bool => $value !== ''
        )));

        return [
            self::TRIGGER_EVENTS => $list($triggers[self::TRIGGER_EVENTS] ?? []),
            self::TRIGGER_ORDER_STATUSES => $list($triggers[self::TRIGGER_ORDER_STATUSES] ?? []),
        ];
    }

    /**
     * @inheritDoc
     */
    public function getMapping(): array
    {
        $mapping = $this->getData(self::MAPPING);

        return is_array($mapping) ? array_values($mapping) : [];
    }

    /**
     * @inheritDoc
     */
    public function setMapping(array $mapping): ProfileInterface
    {
        return $this->setData(self::MAPPING, array_values($mapping));
    }

    /**
     * @inheritDoc
     */
    public function getValueMaps(): array
    {
        $maps = $this->getData(self::VALUE_MAPS);

        return is_array($maps) ? $maps : [];
    }

    /**
     * @inheritDoc
     */
    public function setValueMaps(array $valueMaps): ProfileInterface
    {
        return $this->setData(self::VALUE_MAPS, $valueMaps);
    }

    /**
     * @inheritDoc
     */
    public function getCreatedBy(): ?string
    {
        $value = $this->getData(self::CREATED_BY);

        return $value === null ? null : (string) $value;
    }

    /**
     * @inheritDoc
     */
    public function setCreatedBy(?string $user): ProfileInterface
    {
        return $this->setData(self::CREATED_BY, $user);
    }

    /**
     * @inheritDoc
     */
    public function getCreatedAt(): ?string
    {
        $value = $this->getData(self::CREATED_AT);

        return $value === null ? null : (string) $value;
    }

    /**
     * @inheritDoc
     */
    public function getUpdatedAt(): ?string
    {
        $value = $this->getData(self::UPDATED_AT);

        return $value === null ? null : (string) $value;
    }
}
