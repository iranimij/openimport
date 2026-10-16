<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Export\Destination;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteClientFactory;
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteClientInterface;
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteSettings;
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteSettingsBuilder;
use Iranimij\OpenImport\Model\Export\DestinationInterface;
use Iranimij\OpenImport\Model\Connection\ConnectionConfig;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Writer\WriterInterface;

/**
 * Upload to an SFTP or FTP server (one class, the protocol comes from di.xml). The connection settings live in
 * export_options (host, port, username, path, auth_sftp, ssl, passive, timeout) with secrets like a source's.
 */
class RemoteDestination implements DestinationInterface
{
    /**
     * @param RemoteClientFactory $clients
     * @param RemoteSettingsBuilder $settingsBuilder
     * @param ConnectionConfig $connections
     * @param string $protocol sftp | ftp
     */
    public function __construct(
        private readonly RemoteClientFactory $clients,
        private readonly RemoteSettingsBuilder $settingsBuilder,
        private readonly ConnectionConfig $connections,
        private readonly string $protocol = 'sftp'
    ) {
    }

    /**
     * @inheritDoc
     */
    public function deliver(ProfileInterface $profile, RunInterface $run, string $path, WriterInterface $writer): string
    {
        $config = $profile->getExportOptions();
        $remote = $this->join($this->path($config), (new \SplFileInfo($path))->getFilename());
        $this->withClient($config, static function (RemoteClientInterface $client) use ($path, $remote): void {
            $client->upload($path, $remote);
        });

        return sprintf('Uploaded to %s:%s.', $this->settings($config)->host, $remote);
    }

    /**
     * @inheritDoc
     */
    public function test(ProfileInterface $profile): string
    {
        $config = $profile->getExportOptions();

        return $this->withClient($config, function (RemoteClientInterface $client) use ($config): string {
            $path = $this->path($config);
            $files = $client->listFiles($path);

            return sprintf('Connected. The folder %s has %d file(s).', $path === '' ? '(login folder)' : $path, count($files));
        });
    }

    /**
     * @param array<string, mixed> $config
     * @return RemoteSettings
     * @throws PipelineException
     */
    public function settings(array $config): RemoteSettings
    {
        return $this->settingsBuilder->build($this->connections->resolve($config, $this->protocol), $this->protocol);
    }

    /**
     * @param array<string, mixed> $config
     * @param callable $callback
     * @return mixed
     * @throws PipelineException
     */
    private function withClient(array $config, callable $callback): mixed
    {
        $client = $this->clients->create($this->protocol);
        try {
            $client->connect($this->settings($config));

            return $callback($client);
        } finally {
            $client->close();
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return string
     */
    private function path(array $config): string
    {
        $path = trim((string) ($config['path'] ?? ''));

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * @param string $folder
     * @param string $name
     * @return string
     */
    private function join(string $folder, string $name): string
    {
        return $folder === '' ? $name : rtrim($folder, '/') . '/' . ltrim($name, '/');
    }
}
