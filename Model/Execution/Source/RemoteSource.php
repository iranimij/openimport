<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Execution\Source;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Execution\FetchedFile;
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteClientFactory;
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteClientInterface;
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteSettings;
use Iranimij\OpenImport\Model\Execution\MultiFileSourceInterface;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Security\Secrets;

/**
 * A folder on an SFTP or FTP server (one class, the protocol comes from di.xml).
 *
 * source_config: host, port, username, path (remote folder, default "/"), pattern, pick, move_to (default
 * "processed"), auth_sftp (password | key), ssl and passive (ftp), timeout; secrets: password, private_key,
 * passphrase.
 */
class RemoteSource implements MultiFileSourceInterface
{
    private const DEFAULT_PORTS = ['sftp' => 22, 'ftp' => 21];

    /**
     * @param RemoteClientFactory $clients
     * @param FileSelector $selector
     * @param Secrets $secrets
     * @param string $protocol sftp | ftp
     */
    public function __construct(
        private readonly RemoteClientFactory $clients,
        private readonly FileSelector $selector,
        private readonly Secrets $secrets,
        private readonly string $protocol = 'sftp'
    ) {
    }

    /**
     * @inheritDoc
     */
    public function fetch(ProfileInterface $profile, string $folder): FetchedFile
    {
        return $this->withClient($profile, function (RemoteClientInterface $client) use ($profile, $folder): FetchedFile {
            $config = $profile->getSourceConfig();
            $path = $this->path($config);
            $name = $this->selector->pick(
                $client->listFiles($path),
                (string) ($config['pattern'] ?? '*'),
                (string) ($config['pick'] ?? FileSelector::PICK_NEWEST)
            );
            $local = $folder . '/download-' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
            $client->download($this->join($path, $name), $local);

            return new FetchedFile($local, $name, $this->join($path, $name));
        });
    }

    /**
     * @inheritDoc
     */
    public function names(ProfileInterface $profile): array
    {
        return $this->withClient($profile, function (RemoteClientInterface $client) use ($profile): array {
            $config = $profile->getSourceConfig();

            return $this->selector->ordered($client->listFiles($this->path($config)), (string) ($config['pattern'] ?? '*'));
        });
    }

    /**
     * @inheritDoc
     */
    public function fetchNamed(ProfileInterface $profile, string $folder, string $name): FetchedFile
    {
        return $this->withClient($profile, function (RemoteClientInterface $client) use ($profile, $folder, $name): FetchedFile {
            $path = $this->path($profile->getSourceConfig());
            if (!array_key_exists($name, $client->listFiles($path))) {
                throw new PipelineException(sprintf('The file %s is no longer in %s.', $name, $path));
            }
            $local = $folder . '/download-' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
            $client->download($this->join($path, $name), $local);

            return new FetchedFile($local, $name, $this->join($path, $name));
        });
    }

    /**
     * @inheritDoc
     */
    public function test(ProfileInterface $profile): string
    {
        return $this->withClient($profile, function (RemoteClientInterface $client) use ($profile): string {
            $config = $profile->getSourceConfig();
            $path = $this->path($config);
            $files = $client->listFiles($path);
            $pattern = (string) ($config['pattern'] ?? '*');
            $matching = $this->selector->matching($files, $pattern);
            if ($matching === []) {
                return sprintf(
                    'Connected. The folder %s has %d file(s), none matches the pattern yet.',
                    $path,
                    count($files)
                );
            }

            return sprintf(
                'Connected. %d file(s) in %s match; the next run reads %s.',
                count($matching),
                $path,
                $this->selector->pick($files, $pattern, (string) ($config['pick'] ?? FileSelector::PICK_NEWEST))
            );
        });
    }

    /**
     * @inheritDoc
     */
    public function finish(ProfileInterface $profile, string $reference): void
    {
        $action = $profile->getAfterSuccess();
        if ($reference === '' || $action === 'keep') {
            return;
        }
        $this->withClient($profile, function (RemoteClientInterface $client) use ($profile, $reference, $action): void {
            if ($action === 'delete') {
                $client->delete($reference);
                return;
            }
            $config = $profile->getSourceConfig();
            $moveTo = trim((string) ($config['move_to'] ?? ''), '/');
            $moveTo = $moveTo === '' || str_contains('/' . $moveTo . '/', '/../') ? 'processed' : $moveTo;
            $name = substr($reference, (int) strrpos($reference, '/') + 1);
            $client->move($reference, $this->join($this->join($this->path($config), $moveTo), $name));
        });
    }

    /**
     * Connection settings from a profile's source_config, secrets decrypted.
     *
     * @param array<string, mixed> $config
     * @return RemoteSettings
     * @throws PipelineException
     */
    public function settings(array $config): RemoteSettings
    {
        $host = trim((string) ($config['host'] ?? ''));
        if ($host === '') {
            throw new PipelineException('Enter the server (host name).');
        }
        $port = (int) ($config['port'] ?? 0);
        $key = ($config['auth_sftp'] ?? 'password') === 'key' ? $this->secrets->get($config, 'private_key') : '';

        return new RemoteSettings(
            $host,
            $port > 0 && $port < 65536 ? $port : self::DEFAULT_PORTS[$this->protocol] ?? 22,
            trim((string) ($config['username'] ?? '')),
            $this->secrets->get($config, 'password'),
            $key,
            $key === '' ? '' : $this->secrets->get($config, 'passphrase'),
            !empty($config['ssl']),
            !isset($config['passive']) || !empty($config['passive']),
            max(5, min(300, (int) ($config['timeout'] ?? 30)))
        );
    }

    /**
     * Runs the callback with a connected client and always closes the connection.
     *
     * @param ProfileInterface $profile
     * @param callable $callback receives the RemoteClientInterface
     * @return mixed what the callback returns
     * @throws PipelineException
     */
    private function withClient(ProfileInterface $profile, callable $callback): mixed
    {
        $client = $this->clients->create($this->protocol);
        try {
            $client->connect($this->settings($profile->getSourceConfig()));

            return $callback($client);
        } finally {
            $client->close();
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return string the remote folder without a trailing slash ('' = the login folder)
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
        if ($folder === '') {
            return $name;
        }

        return rtrim($folder, '/') . '/' . ltrim($name, '/');
    }
}
