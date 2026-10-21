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
use Iranimij\OpenImport\Model\Rest\RestClient;

/**
 * "Test connection" on the connection form: logs in and reads the login folder.
 */
class ConnectionTester
{
    /**
     * @param RemoteClientFactory $clients
     * @param RemoteSettingsBuilder $settingsBuilder
     * @param RestClient $rest
     */
    public function __construct(
        private readonly RemoteClientFactory $clients,
        private readonly RemoteSettingsBuilder $settingsBuilder,
        private readonly RestClient $rest
    ) {
    }

    /**
     * @param ConnectionInterface $connection
     * @return string what was found
     * @throws PipelineException when the login fails
     */
    public function test(ConnectionInterface $connection): string
    {
        if ($connection->getType() === 'rest') {
            $settings = $connection->getSettings();
            $answer = $this->rest->send($settings, 'GET', '');
            if (in_array($answer->status, [401, 403], true)) {
                throw new PipelineException(sprintf('The API refused the login (HTTP %d).', $answer->status));
            }

            return sprintf('Reached %s (HTTP %d).', $this->rest->url($settings, ''), $answer->status);
        }
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
