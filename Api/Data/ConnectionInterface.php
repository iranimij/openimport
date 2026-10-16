<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Api\Data;

/**
 * A saved login to a server (SFTP, FTP; later S3, Google, Dropbox, Microsoft, REST) that profiles share.
 * Secrets are kept encrypted inside the settings and never leave the server.
 *
 * @api
 */
interface ConnectionInterface
{
    public const CONNECTION_ID = 'connection_id';
    public const NAME = 'name';
    public const TYPE = 'type';
    public const SETTINGS = 'settings';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    /**
     * @return int|null
     */
    public function getConnectionId(): ?int;

    /**
     * @return string
     */
    public function getName(): string;

    /**
     * @param string $name
     * @return $this
     */
    public function setName(string $name): ConnectionInterface;

    /**
     * @return string sftp, ftp
     */
    public function getType(): string;

    /**
     * @param string $type
     * @return $this
     */
    public function setType(string $type): ConnectionInterface;

    /**
     * Server and login settings; the secrets are encrypted under the key "secrets".
     *
     * @return array<string, mixed>
     */
    public function getSettings(): array;

    /**
     * @param array<string, mixed> $settings
     * @return $this
     */
    public function setSettings(array $settings): ConnectionInterface;

    /**
     * @return string|null
     */
    public function getUpdatedAt(): ?string;
}
