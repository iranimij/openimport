<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Connection;

use Iranimij\OpenImport\Api\Data\ConnectionInterface;

/**
 * A connection without Magento's model layer.
 */
class ConnectionStub implements ConnectionInterface
{
    /**
     * @param int|null $id
     * @param string $name
     * @param string $type
     * @param array<string, mixed> $settings
     */
    public function __construct(
        private ?int $id = null,
        private string $name = '',
        private string $type = 'sftp',
        private array $settings = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getConnectionId(): ?int
    {
        return $this->id;
    }

    /**
     * @param int $id
     * @return $this
     */
    public function setConnectionId(int $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @inheritDoc
     */
    public function setName(string $name): ConnectionInterface
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @inheritDoc
     */
    public function setType(string $type): ConnectionInterface
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * @inheritDoc
     */
    public function setSettings(array $settings): ConnectionInterface
    {
        $this->settings = $settings;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getUpdatedAt(): ?string
    {
        return null;
    }
}
