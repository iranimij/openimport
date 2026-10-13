<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model;

use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Model\JsonField;

/**
 * A run without Magento's model layer, for unit tests. Same getters and setters as Model\Run.
 */
class RunStub implements RunInterface
{
    private JsonField $json;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data = [])
    {
        $this->json = new JsonField();
        if (isset($this->data['options']) && is_array($this->data['options'])) {
            $this->data['options'] = $this->json->encode($this->data['options']);
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
    public function getRunId(): ?int
    {
        $id = $this->get(self::RUN_ID);

        return $id === null || $id === '' ? null : (int) $id;
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
    public function setProfileId(?int $profileId): RunInterface
    {
        return $this->set(self::PROFILE_ID, $profileId);
    }

    /**
     * @inheritDoc
     */
    public function getProfileName(): string
    {
        return (string) $this->get(self::PROFILE_NAME);
    }

    /**
     * @inheritDoc
     */
    public function setProfileName(string $name): RunInterface
    {
        return $this->set(self::PROFILE_NAME, $name);
    }

    /**
     * @inheritDoc
     */
    public function getDirection(): string
    {
        return (string) ($this->get(self::DIRECTION) ?: 'import');
    }

    /**
     * @inheritDoc
     */
    public function setDirection(string $direction): RunInterface
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
    public function setEntity(string $entity): RunInterface
    {
        return $this->set(self::ENTITY, $entity);
    }

    /**
     * @inheritDoc
     */
    public function getTrigger(): string
    {
        return (string) ($this->get(self::TRIGGER) ?: self::TRIGGER_MANUAL);
    }

    /**
     * @inheritDoc
     */
    public function setTrigger(string $trigger): RunInterface
    {
        return $this->set(self::TRIGGER, $trigger);
    }

    /**
     * @inheritDoc
     */
    public function getMode(): string
    {
        return (string) ($this->get(self::MODE) ?: self::MODE_DRY_RUN);
    }

    /**
     * @inheritDoc
     */
    public function setMode(string $mode): RunInterface
    {
        return $this->set(self::MODE, $mode);
    }

    /**
     * @inheritDoc
     */
    public function isDryRun(): bool
    {
        return $this->getMode() !== self::MODE_LIVE;
    }

    /**
     * @inheritDoc
     */
    public function getStatus(): string
    {
        return (string) ($this->get(self::STATUS) ?: self::STATUS_QUEUED);
    }

    /**
     * @inheritDoc
     */
    public function setStatus(string $status): RunInterface
    {
        return $this->set(self::STATUS, $status);
    }

    /**
     * @inheritDoc
     */
    public function getCount(string $name): int
    {
        return (int) $this->get('count_' . $name);
    }

    /**
     * @inheritDoc
     */
    public function setCount(string $name, int $value): RunInterface
    {
        if (!in_array($name, self::COUNTS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown run count "%s".', $name));
        }

        return $this->set('count_' . $name, max(0, $value));
    }

    /**
     * @inheritDoc
     */
    public function getCounts(): array
    {
        $counts = [];
        foreach (self::COUNTS as $name) {
            $counts[$name] = $this->getCount($name);
        }

        return $counts;
    }

    /**
     * @inheritDoc
     */
    public function getSourceFile(): ?string
    {
        $value = $this->get(self::SOURCE_FILE);

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * @inheritDoc
     */
    public function setSourceFile(?string $file): RunInterface
    {
        return $this->set(self::SOURCE_FILE, $file);
    }

    /**
     * @inheritDoc
     */
    public function getOutputFile(): ?string
    {
        $value = $this->get(self::OUTPUT_FILE);

        return $value === null || $value === '' ? null : (string) $value;
    }

    /**
     * @inheritDoc
     */
    public function setOutputFile(?string $file): RunInterface
    {
        return $this->set(self::OUTPUT_FILE, $file);
    }

    /**
     * @inheritDoc
     */
    public function getOptions(): array
    {
        return $this->json->decode($this->get(self::OPTIONS));
    }

    /**
     * @inheritDoc
     */
    public function setOptions(array $options): RunInterface
    {
        return $this->set(self::OPTIONS, $this->json->encode($options));
    }

    /**
     * @inheritDoc
     */
    public function getMessage(): string
    {
        return (string) $this->get(self::MESSAGE);
    }

    /**
     * @inheritDoc
     */
    public function setMessage(string $message): RunInterface
    {
        return $this->set(self::MESSAGE, $message);
    }

    /**
     * @inheritDoc
     */
    public function getRequestedBy(): ?string
    {
        $value = $this->get(self::REQUESTED_BY);

        return $value === null ? null : (string) $value;
    }

    /**
     * @inheritDoc
     */
    public function setRequestedBy(?string $requestedBy): RunInterface
    {
        return $this->set(self::REQUESTED_BY, $requestedBy);
    }

    /**
     * @inheritDoc
     */
    public function getParentRunId(): ?int
    {
        $id = $this->get(self::PARENT_RUN_ID);

        return $id === null || $id === '' ? null : (int) $id;
    }

    /**
     * @inheritDoc
     */
    public function setParentRunId(?int $runId): RunInterface
    {
        return $this->set(self::PARENT_RUN_ID, $runId);
    }

    /**
     * @inheritDoc
     */
    public function getProgressRows(): int
    {
        return (int) $this->get(self::PROGRESS_ROWS);
    }

    /**
     * @inheritDoc
     */
    public function getPeakMemoryMb(): int
    {
        return (int) $this->get(self::PEAK_MEMORY_MB);
    }

    /**
     * @inheritDoc
     */
    public function setPeakMemoryMb(int $megabytes): RunInterface
    {
        return $this->set(self::PEAK_MEMORY_MB, max(0, $megabytes));
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
    public function getStartedAt(): ?string
    {
        $value = $this->get(self::STARTED_AT);

        return $value === null ? null : (string) $value;
    }

    /**
     * @inheritDoc
     */
    public function setStartedAt(?string $time): RunInterface
    {
        return $this->set(self::STARTED_AT, $time);
    }

    /**
     * @inheritDoc
     */
    public function getFinishedAt(): ?string
    {
        $value = $this->get(self::FINISHED_AT);

        return $value === null ? null : (string) $value;
    }

    /**
     * @inheritDoc
     */
    public function setFinishedAt(?string $time): RunInterface
    {
        return $this->set(self::FINISHED_AT, $time);
    }
}
