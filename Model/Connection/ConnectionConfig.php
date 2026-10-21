<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Connection;

use Iranimij\OpenImport\Api\ConnectionRepositoryInterface;
use Iranimij\OpenImport\Api\Data\ConnectionInterface;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Security\Secrets;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * A profile's source (source_config) or destination (export_options) settings can name a saved connection with
 * "connection_id". The connection then gives the server and login; the profile keeps its folder, file pattern and
 * what to do after success.
 */
class ConnectionConfig
{
    public const ID = 'connection_id';

    /**
     * Settings that belong to the connection, not to the profile.
     */
    public const LOGIN_KEYS = ['host', 'port', 'username', 'auth_sftp', 'ssl', 'passive', 'timeout', 'endpoint',
        'region', 'bucket', 'access_key', 'path_style', 'auth_google', 'client_id', 'account', Secrets::KEY];

    /**
     * @param ConnectionRepositoryInterface $connections
     * @param ConnectionTypes $types
     */
    public function __construct(
        private readonly ConnectionRepositoryInterface $connections,
        private readonly ConnectionTypes $types
    ) {
    }

    /**
     * @param array<string, mixed> $config
     * @return int the connection the settings name, 0 for none (the login is in the profile)
     */
    public function selected(array $config): int
    {
        $id = $config[self::ID] ?? '';

        return is_scalar($id) && ctype_digit((string) $id) ? (int) $id : 0;
    }

    /**
     * The settings to connect with: the profile's own, or the profile's folder settings with the saved connection's
     * server and login.
     *
     * @param array<string, mixed> $config source_config or export_options
     * @param string $type the profile's source type or destination (sftp, ftp)
     * @return array<string, mixed>
     * @throws PipelineException when the connection is gone or of another type
     */
    public function resolve(array $config, string $type): array
    {
        if ($this->selected($config) === 0) {
            return $config;
        }
        $connection = $this->connection($config, $type);
        foreach (self::LOGIN_KEYS as $key) {
            unset($config[$key]);
        }

        return array_merge($config, array_intersect_key($connection->getSettings(), array_flip(self::LOGIN_KEYS)));
    }

    /**
     * What is wrong with the chosen connection, for saving a profile.
     *
     * @param array<string, mixed> $config
     * @param string $type
     * @return string|null null when no connection is chosen or it fits
     */
    public function problem(array $config, string $type): ?string
    {
        if ($this->selected($config) === 0) {
            return null;
        }
        try {
            $this->connection($config, $type);
        } catch (PipelineException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    /**
     * @param string $name
     * @return int|null the id of the connection with this name
     */
    public function idByName(string $name): ?int
    {
        try {
            return $this->connections->getByName($name)->getConnectionId();
        } catch (NoSuchEntityException $exception) {
            return null;
        }
    }

    /**
     * @param int $connectionId
     * @return string|null the connection's name
     */
    public function nameById(int $connectionId): ?string
    {
        try {
            return $this->connections->getById($connectionId)->getName();
        } catch (NoSuchEntityException $exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param string $type
     * @return ConnectionInterface
     * @throws PipelineException
     */
    private function connection(array $config, string $type): ConnectionInterface
    {
        $id = $this->selected($config);
        try {
            $connection = $this->connections->getById($id);
        } catch (NoSuchEntityException $exception) {
            throw new PipelineException(sprintf(
                'The saved connection %d no longer exists. Choose another one, or enter the login in the profile.',
                $id
            ));
        }
        if ($connection->getType() !== $type) {
            throw new PipelineException(sprintf(
                'The saved connection "%s" is for %s, not for %s.',
                $connection->getName(),
                $this->types->label($connection->getType()),
                $this->types->label($type)
            ));
        }

        return $connection;
    }
}
