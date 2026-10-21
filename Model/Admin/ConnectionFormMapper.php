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
            'settings' => $settings + ['auth_sftp' => 'password', 'passive' => '1', 'ssl' => '0', 'path_style' => '0',
                'auth_google' => 'service_account', 'auth_rest' => 'none'],
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

        $stored = $connection->getSettings();
        unset($settings['account']);
        // The account a service account key belongs to is shown on the form (the key itself never is).
        $key = json_decode((string) ($settings['service_account_json'] ?? ''), true);
        if (is_array($key) && is_string($key['client_email'] ?? null)) {
            $settings['account'] = $key['client_email'];
        } elseif (($settings['service_account_json'] ?? '') !== '') {
            $settings['account'] = '';
        } elseif (isset($stored['account'])) {
            // Not a form field: set by the key above or by "Sign in with Google".
            $settings['account'] = (string) $stored['account'];
        }
        if ($text('type') === 'google_drive' && ($settings['auth_google'] ?? '') !== ($stored['auth_google'] ?? '')
            && isset($stored['auth_google'])
        ) {
            // Another kind of login: the old account and its tokens no longer apply.
            unset($settings['account'], $stored['secrets']['refresh_token']);
        }

        return $connection
            ->setName($text('name'))
            ->setType($text('type'))
            ->setSettings($this->secrets->merge($settings, $stored));
    }
}
