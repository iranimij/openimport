<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Connection;

use Iranimij\OpenImport\Api\Data\ConnectionInterface;
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteClientFactory;
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteSettingsBuilder;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;

/**
 * "Test connection" on the connection form: logs in and reads the login folder.
 */
class ConnectionTester
{
    /**
     * @param RemoteClientFactory $clients
     * @param RemoteSettingsBuilder $settingsBuilder
     */
    public function __construct(
        private readonly RemoteClientFactory $clients,
        private readonly RemoteSettingsBuilder $settingsBuilder
    ) {
    }

    /**
     * @param ConnectionInterface $connection
     * @return string what was found
     * @throws PipelineException when the login fails
     */
    public function test(ConnectionInterface $connection): string
    {
        $settings = $this->settingsBuilder->build($connection->getSettings(), $connection->getType());
        $client = $this->clients->create($connection->getType());
        try {
            $client->connect($settings);
            $files = $client->listFiles('');
        } finally {
            $client->close();
        }

        return sprintf('Connected to %s. The login folder has %d file(s).', $settings->label(), count($files));
    }
}
