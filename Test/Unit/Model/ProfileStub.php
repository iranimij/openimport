<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\JsonField;

/**
 * A profile without Magento's model layer, for unit tests. Same getters and setters as Model\Profile.
 */
class ProfileStub implements ProfileInterface
{
    private JsonField $json;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data = [])
    {
        $this->json = new JsonField();
        foreach (['source_config', 'format_options', 'row_filters', 'export_options', 'import_options'] as $key) {
            if (isset($this->data[$key]) && is_array($this->data[$key])) {
                $this->data[$key] = $this->json->encode($this->data[$key]);
            }
        }
    }

    private function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    private function set(string $key, mixed $value): static
    {
        $this->data[$key] = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getProfileId(): ?int
    {
        $id = $this->get(self::PROFILE_ID);

        return $id === null || $id === '' ? null : (int) $id;
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return (string) $this->get(self::NAME);
    }

    /**
     * @inheritDoc
     */
    public function setName(string $name): ProfileInterface
    {
        return $this->set(self::NAME, $name);
    }

    /**
     * @inheritDoc
     */
    public function getDirection(): string
    {
        return (string) ($this->get(self::DIRECTION) ?: self::DIRECTION_IMPORT);
    }

    /**
     * @inheritDoc
     */
    public function setDirection(string $direction): ProfileInterface
    {
        return $this->set(self::DIRECTION, $direction);
    }

    /**
     * @inheritDoc
     */
    public function getEntity(): string
    {
        return (string) $this->get(self::ENTITY);
    }

    /**
     * @inheritDoc
     */
    public function setEntity(string $entity): ProfileInterface
    {
        return $this->set(self::ENTITY, $entity);
    }

    /**
     * @inheritDoc
     */
    public function isActive(): bool
    {
        return (bool) $this->get(self::IS_ACTIVE);
    }

    /**
     * @inheritDoc
     */
    public function setIsActive(bool $active): ProfileInterface
    {
        return $this->set(self::IS_ACTIVE, (int) $active);
    }

    /**
     * @inheritDoc
     */
    public function getSourceType(): string
    {
        return (string) ($this->get(self::SOURCE_TYPE) ?: 'upload');
    }

    /**
     * @inheritDoc
     */
    public function setSourceType(string $type): ProfileInterface
    {
        return $this->set(self::SOURCE_TYPE, $type);
    }

    /**
     * @inheritDoc
     */
    public function getSourceConfig(): array
    {
        return $this->json->decode($this->get(self::SOURCE_CONFIG));
    }

    /**
     * @inheritDoc
     */
    public function setSourceConfig(array $config): ProfileInterface
    {
        return $this->set(self::SOURCE_CONFIG, $this->json->encode($config));
    }

    /**
     * @inheritDoc
     */
    public function getFormat(): string
    {
        return (string) ($this->get(self::FORMAT) ?: 'auto');
    }

    /**
     * @inheritDoc
     */
    public function setFormat(string $format): ProfileInterface
    {
        return $this->set(self::FORMAT, $format);
    }

    /**
     * @inheritDoc
     */
    public function getFormatOptions(): array
    {
        return $this->json->decode($this->get(self::FORMAT_OPTIONS));
    }

    /**
     * @inheritDoc
     */
    public function setFormatOptions(array $options): ProfileInterface
    {
        return $this->set(self::FORMAT_OPTIONS, $this->json->encode($options));
    }

    /**
     * @inheritDoc
     */
    public function getBehavior(): string
    {
        return (string) ($this->get(self::BEHAVIOR) ?: self::BEHAVIOR_ADD_UPDATE);
    }

    /**
     * @inheritDoc
     */
    public function setBehavior(string $behavior): ProfileInterface
    {
        return $this->set(self::BEHAVIOR, $behavior);
    }

    /**
     * @inheritDoc
     */
    public function getOnError(): string
    {
        return (string) ($this->get(self::ON_ERROR) ?: self::ON_ERROR_STOP);
    }

    /**
     * @inheritDoc
     */
    public function setOnError(string $onError): ProfileInterface
    {
        return $this->set(self::ON_ERROR, $onError);
    }

    /**
     * @inheritDoc
     */
    public function getAllowedErrors(): int
    {
        return (int) $this->get(self::ALLOWED_ERRORS);
    }

    /**
     * @inheritDoc
     */
    public function setAllowedErrors(int $allowed): ProfileInterface
    {
        return $this->set(self::ALLOWED_ERRORS, max(0, $allowed));
    }

    /**
     * @inheritDoc
     */
    public function isDelta(): bool
    {
        return (bool) $this->get(self::DELTA);
    }

    /**
     * @inheritDoc
     */
    public function setDelta(bool $delta): ProfileInterface
    {
        return $this->set(self::DELTA, (int) $delta);
    }

    /**
     * @inheritDoc
     */
    public function getSafetyThreshold(): ?int
    {
        $value = $this->get(self::SAFETY_THRESHOLD);

        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * @inheritDoc
     */
    public function setSafetyThreshold(?int $percent): ProfileInterface
    {
        return $this->set(self::SAFETY_THRESHOLD, $percent);
    }

    /**
     * @inheritDoc
     */
    public function getSchedule(): ?string
    {
        $value = trim((string) $this->get(self::SCHEDULE));

        return $value === '' ? null : $value;
    }

    /**
     * @inheritDoc
     */
    public function setSchedule(?string $schedule): ProfileInterface
    {
        return $this->set(self::SCHEDULE, $schedule === null || trim($schedule) === '' ? null : trim($schedule));
    }

    /**
     * @inheritDoc
     */
    public function getAfterSuccess(): string
    {
        return (string) ($this->get(self::AFTER_SUCCESS) ?: 'keep');
    }

    /**
     * @inheritDoc
     */
    public function setAfterSuccess(string $afterSuccess): ProfileInterface
    {
        return $this->set(self::AFTER_SUCCESS, $afterSuccess);
    }

    /**
     * @inheritDoc
     */
    public function getNotify(): string
    {
        return (string) ($this->get(self::NOTIFY) ?: 'failure');
    }

    /**
     * @inheritDoc
     */
    public function setNotify(string $notify): ProfileInterface
    {
        return $this->set(self::NOTIFY, $notify);
    }

    /**
     * @inheritDoc
     */
    public function getNotifyEmails(): string
    {
        return (string) $this->get(self::NOTIFY_EMAILS);
    }

    /**
     * @inheritDoc
     */
    public function setNotifyEmails(string $emails): ProfileInterface
    {
        return $this->set(self::NOTIFY_EMAILS, $emails);
    }

    /**
     * @inheritDoc
     */
    public function getRowFilters(): array
    {
        return $this->json->decode($this->get(self::ROW_FILTERS));
    }

    /**
     * @inheritDoc
     */
    public function setRowFilters(array $filters): ProfileInterface
    {
        return $this->set(self::ROW_FILTERS, $this->json->encode($filters));
    }

    /**
     * @inheritDoc
     */
    public function getExportOptions(): array
    {
        return $this->json->decode($this->get(self::EXPORT_OPTIONS));
    }

    /**
     * @inheritDoc
     */
    public function setExportOptions(array $options): ProfileInterface
    {
        return $this->set(self::EXPORT_OPTIONS, $this->json->encode($options));
    }

    /**
     * @inheritDoc
     */
    public function getImportOptions(): array
    {
        return $this->json->decode($this->get(self::IMPORT_OPTIONS));
    }

    /**
     * @inheritDoc
     */
    public function setImportOptions(array $options): ProfileInterface
    {
        return $this->set(self::IMPORT_OPTIONS, $this->json->encode($options));
    }

    /**
     * @inheritDoc
     */
    public function getMapping(): array
    {
        $mapping = $this->get(self::MAPPING);

        return is_array($mapping) ? array_values($mapping) : [];
    }

    /**
     * @inheritDoc
     */
    public function setMapping(array $mapping): ProfileInterface
    {
        return $this->set(self::MAPPING, array_values($mapping));
    }

    /**
     * @inheritDoc
     */
    public function getValueMaps(): array
    {
        $maps = $this->get(self::VALUE_MAPS);

        return is_array($maps) ? $maps : [];
    }

    /**
     * @inheritDoc
     */
    public function setValueMaps(array $valueMaps): ProfileInterface
    {
        return $this->set(self::VALUE_MAPS, $valueMaps);
    }

    /**
     * @inheritDoc
     */
    public function getCreatedBy(): ?string
    {
        $value = $this->get(self::CREATED_BY);

        return $value === null ? null : (string) $value;
    }

    /**
     * @inheritDoc
     */
    public function setCreatedBy(?string $user): ProfileInterface
    {
        return $this->set(self::CREATED_BY, $user);
    }

    /**
     * @inheritDoc
     */
    public function getCreatedAt(): ?string
    {
        $value = $this->get(self::CREATED_AT);

        return $value === null ? null : (string) $value;
    }

    /**
     * @inheritDoc
     */
    public function getUpdatedAt(): ?string
    {
        $value = $this->get(self::UPDATED_AT);

        return $value === null ? null : (string) $value;
    }
}
