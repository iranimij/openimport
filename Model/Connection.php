<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model;

use Iranimij\OpenImport\Api\Data\ConnectionInterface;
use Iranimij\OpenImport\Model\ResourceModel\Connection as ConnectionResource;
use Magento\Framework\Model\AbstractModel;

/**
 * A saved connection.
 */
class Connection extends AbstractModel implements ConnectionInterface
{
    /**
     * @var string
     */
    protected $_eventPrefix = 'openimport_connection';

    /**
     * @var JsonField|null
     */
    private ?JsonField $json = null;

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(ConnectionResource::class);
    }

    /**
     * @return JsonField
     */
    private function json(): JsonField
    {
        return $this->json ??= new JsonField();
    }

    /**
     * @inheritDoc
     */
    public function getConnectionId(): ?int
    {
        $id = $this->getData(self::CONNECTION_ID);

        return $id === null ? null : (int) $id;
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
    public function setName(string $name): ConnectionInterface
    {
        return $this->setData(self::NAME, $name);
    }

    /**
     * @inheritDoc
     */
    public function getType(): string
    {
        return (string) $this->getData(self::TYPE);
    }

    /**
     * @inheritDoc
     */
    public function setType(string $type): ConnectionInterface
    {
        return $this->setData(self::TYPE, $type);
    }

    /**
     * @inheritDoc
     */
    public function getSettings(): array
    {
        return $this->json()->decode($this->getData(self::SETTINGS));
    }

    /**
     * @inheritDoc
     */
    public function setSettings(array $settings): ConnectionInterface
    {
        return $this->setData(self::SETTINGS, $this->json()->encode($settings));
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
