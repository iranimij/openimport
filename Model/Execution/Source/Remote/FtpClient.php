<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Execution\Source\Remote;

use Iranimij\OpenImport\Model\Pipeline\PipelineException;

/**
 * FTP and explicit FTPS through PHP's ftp extension (a Magento requirement), passive mode by default.
 *
 * Magento's own Io\Ftp wrapper is not used: it gives no modification times (needed for "newest file") and no MLSD.
 * The ftp_* functions report failures with PHP warnings; they are silenced here and turned into plain messages.
 */
// phpcs:disable Magento2.Functions.DiscouragedFunction, Generic.PHP.NoSilencedErrors
class FtpClient implements RemoteClientInterface
{
    /**
     * @var \FTP\Connection|null
     */
    private $connection = null;

    private string $label = '';

    /**
     * @inheritDoc
     */
    public function connect(RemoteSettings $settings): void
    {
        $this->label = $settings->label();
        $connection = $settings->ssl
            ? @ftp_ssl_connect($settings->host, $settings->port, $settings->timeout)
            : @ftp_connect($settings->host, $settings->port, $settings->timeout);
        if ($connection === false) {
            throw new PipelineException(sprintf('Cannot connect to %s.', $this->label));
        }
        if (!@ftp_login($connection, $settings->username, $settings->password)) {
            ftp_close($connection);
            throw new PipelineException(sprintf(
                'Connected to %s, but the login was refused. Check the user name and the password.',
                $this->label
            ));
        }
        ftp_pasv($connection, $settings->passive);
        $this->connection = $connection;
    }

    /**
     * @inheritDoc
     */
    public function listFiles(string $path): array
    {
        $connection = $this->connection();
        $folder = $path === '' ? '.' : $path;
        $entries = @ftp_mlsd($connection, $folder);
        $files = [];
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if (($entry['type'] ?? '') === 'file' && isset($entry['name'])) {
                    $modified = isset($entry['modify'])
                        ? \DateTimeImmutable::createFromFormat('YmdHis', substr((string) $entry['modify'], 0, 14), new \DateTimeZone('UTC'))
                        : false;
                    $files[(string) $entry['name']] = $modified === false ? null : $modified->getTimestamp();
                }
            }

            return $files;
        }
        // Servers without MLSD: names, then size (folders have none) and modification time per file.
        $names = @ftp_nlist($connection, $folder);
        if (!is_array($names)) {
            throw new PipelineException(sprintf('The folder %s cannot be read on %s.', $path, $this->label));
        }
        foreach ($names as $name) {
            $name = substr((string) $name, (int) strrpos('/' . $name, '/'));
            $remote = $path === '' ? $name : rtrim($path, '/') . '/' . $name;
            if ($name === '.' || $name === '..' || @ftp_size($connection, $remote) < 0) {
                continue;
            }
            $time = @ftp_mdtm($connection, $remote);
            $files[$name] = $time > 0 ? $time : null;
        }

        return $files;
    }

    /**
     * @inheritDoc
     */
    public function download(string $remote, string $local): void
    {
        if (!@ftp_get($this->connection(), $local, $remote, FTP_BINARY)) {
            throw new PipelineException(sprintf('%s could not be downloaded from %s.', $remote, $this->label));
        }
    }

    /**
     * @inheritDoc
     */
    public function upload(string $local, string $remote): void
    {
        if (!@ftp_put($this->connection(), $remote, $local, FTP_BINARY)) {
            throw new PipelineException(sprintf('%s could not be uploaded to %s.', $remote, $this->label));
        }
    }

    /**
     * @inheritDoc
     */
    public function move(string $from, string $to): void
    {
        $connection = $this->connection();
        $folder = substr($to, 0, (int) strrpos($to, '/'));
        if ($folder !== '') {
            $this->createFolder($folder);
        }
        if (@ftp_size($connection, $to) >= 0) {
            $to .= '-' . gmdate('Ymd-His');
        }
        if (!@ftp_rename($connection, $from, $to)) {
            throw new PipelineException(sprintf('%s could not be moved to %s on %s.', $from, $to, $this->label));
        }
    }

    /**
     * @inheritDoc
     */
    public function delete(string $remote): void
    {
        if (!@ftp_delete($this->connection(), $remote)) {
            throw new PipelineException(sprintf('%s could not be deleted on %s.', $remote, $this->label));
        }
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        if ($this->connection !== null) {
            @ftp_close($this->connection);
        }
        $this->connection = null;
    }

    /**
     * Creates a folder and its parents when missing.
     *
     * @param string $folder
     * @return void
     */
    private function createFolder(string $folder): void
    {
        $connection = $this->connection();
        $current = str_starts_with($folder, '/') ? '' : '.';
        foreach (array_filter(explode('/', $folder), static fn (string $part): bool => $part !== '') as $part) {
            $current .= '/' . $part;
            $here = @ftp_pwd($connection);
            if (@ftp_chdir($connection, $current)) {
                @ftp_chdir($connection, (string) $here);
                continue;
            }
            @ftp_mkdir($connection, $current);
        }
    }

    /**
     * @return \FTP\Connection
     */
    private function connection()
    {
        if ($this->connection === null) {
            throw new PipelineException('Not connected.');
        }

        return $this->connection;
    }
}
