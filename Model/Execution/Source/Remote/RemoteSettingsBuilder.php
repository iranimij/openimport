<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Execution\Source\Remote;

use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Security\Secrets;

/**
 * Server settings from a profile's source or destination settings, or from a saved connection.
 */
class RemoteSettingsBuilder
{
    private const DEFAULT_PORTS = ['sftp' => 22, 'ftp' => 21];

    /**
     * @param Secrets $secrets
     */
    public function __construct(
        private readonly Secrets $secrets
    ) {
    }

    /**
     * @param array<string, mixed> $config host, port, username, auth_sftp, ssl, passive, timeout, secrets
     * @param string $protocol sftp or ftp
     * @return RemoteSettings
     * @throws PipelineException without a host
     */
    public function build(array $config, string $protocol): RemoteSettings
    {
        $host = trim((string) ($config['host'] ?? ''));
        if ($host === '') {
            throw new PipelineException('Enter the server (host name).');
        }
        $port = (int) ($config['port'] ?? 0);
        $key = ($config['auth_sftp'] ?? 'password') === 'key' ? $this->secrets->get($config, 'private_key') : '';

        return new RemoteSettings(
            $host,
            $port > 0 && $port < 65536 ? $port : self::DEFAULT_PORTS[$protocol] ?? 22,
            trim((string) ($config['username'] ?? '')),
            $this->secrets->get($config, 'password'),
            $key,
            $key === '' ? '' : $this->secrets->get($config, 'passphrase'),
            !empty($config['ssl']),
            !isset($config['passive']) || !empty($config['passive']),
            max(5, min(300, (int) ($config['timeout'] ?? 30)))
        );
    }
}
