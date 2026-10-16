<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Connection;

use Iranimij\OpenImport\Api\Data\ConnectionInterface;

/**
 * What a connection needs before it is saved. Whether the login works is for "Test connection".
 */
class ConnectionValidator
{
    /**
     * @param ConnectionTypes $types
     */
    public function __construct(
        private readonly ConnectionTypes $types
    ) {
    }

    /**
     * @param ConnectionInterface $connection
     * @return string[] problems, empty when the connection can be saved
     */
    public function validate(ConnectionInterface $connection): array
    {
        $problems = [];
        $name = trim($connection->getName());
        if ($name === '') {
            $problems[] = 'Give the connection a name.';
        } elseif (mb_strlen($name) > 255) {
            $problems[] = 'The name is longer than 255 characters.';
        }
        if (!$this->types->has($connection->getType())) {
            $problems[] = sprintf(
                'Choose what the connection is for (%s).',
                implode(', ', array_values($this->types->all()))
            );

            return $problems;
        }
        $settings = $connection->getSettings();
        $text = static fn (string $key): string
            => is_scalar($settings[$key] ?? null) ? trim((string) $settings[$key]) : '';
        if ($text('host') === '') {
            $problems[] = 'Enter the server (host name).';
        }
        if ($text('username') === '') {
            $problems[] = 'Enter the user name for the server.';
        }
        $port = $text('port');
        if ($port !== '' && (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535)) {
            $problems[] = 'The port must be a number between 1 and 65535.';
        }
        if (!in_array($text('auth_sftp') ?: 'password', ['password', 'key'], true)) {
            $problems[] = 'Log in with a password or a private key.';
        }

        return $problems;
    }
}
