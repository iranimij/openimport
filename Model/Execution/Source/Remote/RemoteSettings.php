<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Execution\Source\Remote;

/**
 * Connection settings of an SFTP or FTP source or destination, with its secrets decrypted. Never logged.
 */
class RemoteSettings
{
    /**
     * @param string $host
     * @param int $port
     * @param string $username
     * @param string $password
     * @param string $privateKey SFTP: PEM/OpenSSH private key ('' = password login)
     * @param string $passphrase SFTP: passphrase of the private key
     * @param bool $ssl FTP: explicit FTPS
     * @param bool $passive FTP: passive mode
     * @param int $timeout seconds
     */
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        public readonly string $password = '',
        public readonly string $privateKey = '',
        public readonly string $passphrase = '',
        public readonly bool $ssl = false,
        public readonly bool $passive = true,
        public readonly int $timeout = 30
    ) {
    }

    /**
     * @return string host:port, for messages
     */
    public function label(): string
    {
        return $this->host . ':' . $this->port;
    }
}
