<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Admin;

use Iranimij\OpenImport\Api\Data\ConnectionInterface;
use Iranimij\OpenImport\Model\Connection\ConnectionConfig;
use Iranimij\OpenImport\Model\Security\Secrets;

/**
 * Connection <-> form data. Secrets never reach the form: it only learns whether one is stored ("<name>_stored").
 * An empty secret field keeps the stored value.
 */
class ConnectionFormMapper
{
    /**
     * @param Secrets $secrets
     */
    public function __construct(
        private readonly Secrets $secrets
    ) {
    }

    /**
     * @param ConnectionInterface $connection
     * @return array<string, mixed>
     */
    public function toForm(ConnectionInterface $connection): array
    {
        $settings = $this->secrets->strip($connection->getSettings());
        foreach ($this->secrets->stored($connection->getSettings()) as $name => $stored) {
            $settings[$name . '_stored'] = $stored ? '1' : '0';
        }

        return [
            'connection_id' => $connection->getConnectionId(),
            'name' => $connection->getName(),
            'type' => $connection->getType() ?: 'sftp',
            'settings' => $settings + ['auth_sftp' => 'password', 'passive' => '1', 'ssl' => '0', 'path_style' => '0'],
        ];
    }

    /**
     * @param array<string, mixed> $data posted form data
     * @param ConnectionInterface $connection
     * @return ConnectionInterface
     */
    public function fromForm(array $data, ConnectionInterface $connection): ConnectionInterface
    {
        $text = static fn (string $key): string
            => isset($data[$key]) && is_scalar($data[$key]) ? trim((string) $data[$key]) : '';
        $posted = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $settings = [];
        foreach ($posted as $key => $value) {
            $key = (string) $key;
            if (is_scalar($value) && !str_ends_with($key, '_stored')
                && (in_array($key, ConnectionConfig::LOGIN_KEYS, true) || in_array($key, Secrets::NAMES, true)
                    || str_ends_with($key, '_clear'))
            ) {
                $settings[$key] = trim((string) $value);
            }
        }

        return $connection
            ->setName($text('name'))
            ->setType($text('type'))
            ->setSettings($this->secrets->merge($settings, $connection->getSettings()));
    }
}
