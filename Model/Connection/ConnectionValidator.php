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
        if ($connection->getType() === 's3') {
            return array_merge($problems, $this->s3($text, isset($settings['secrets']['secret_key'])));
        }
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

    /**
     * @param callable $text setting => trimmed text
     * @param bool $secretStored
     * @return string[]
     */
    private function s3(callable $text, bool $secretStored): array
    {
        $problems = [];
        $endpoint = $text('endpoint');
        if ($endpoint !== '' && preg_match('#^https?://[^/\s]+/?$#i', $endpoint) !== 1) {
            $problems[] = 'The endpoint must be a web address such as https://s3.eu-central-1.amazonaws.com.';
        }
        if ($endpoint === '' && $text('region') === '') {
            $problems[] = 'Enter the region of the bucket (e.g. eu-central-1), or the endpoint of an S3-compatible '
                . 'service.';
        }
        $bucket = $text('bucket');
        if ($bucket === '') {
            $problems[] = 'Enter the bucket.';
        } elseif (preg_match('/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/', $bucket) !== 1) {
            $problems[] = 'A bucket name has 3 to 63 lower-case letters, digits, dots and hyphens.';
        }
        if ($text('access_key') === '') {
            $problems[] = 'Enter the access key.';
        }
        if (!$secretStored) {
            $problems[] = 'Enter the secret key.';
        }

        return $problems;
    }
}
