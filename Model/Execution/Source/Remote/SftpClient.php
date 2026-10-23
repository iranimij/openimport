<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Execution\Source\Remote;

use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

/**
 * SFTP through phpseclib 3 (part of every Magento installation), with password or private-key login. Magento's own
 * SFTP class only knows passwords.
 */
class SftpClient implements RemoteClientInterface
{
    private ?SFTP $sftp = null;

    private string $label = '';

    /**
     * @inheritDoc
     */
    public function connect(RemoteSettings $settings): void
    {
        $this->label = $settings->label();
        try {
            $sftp = new SFTP($settings->host, $settings->port, $settings->timeout);
            $credential = $settings->password;
            if ($settings->privateKey !== '') {
                $credential = PublicKeyLoader::load(
                    (new PrivateKeyText())->normalize($settings->privateKey),
                    $settings->passphrase === '' ? false : $settings->passphrase
                );
            }
            $loggedIn = $sftp->login($settings->username, $credential);
        } catch (\Throwable $exception) {
            throw new PipelineException(sprintf(
                'Cannot connect to %s: %s',
                $this->label,
                $this->reason($exception->getMessage())
            ));
        }
        if (!$loggedIn) {
            throw new PipelineException(sprintf(
                'Connected to %s, but the login was refused. Check the user name and %s.',
                $this->label,
                $settings->privateKey !== '' ? 'the private key' : 'the password'
            ));
        }
        $this->sftp = $sftp;
    }

    /**
     * The network library's message in plain words ("Cannot connect to host:22. Error 0. php_network_getaddresses:
     * getaddrinfo … failed" says that the host name is unknown).
     *
     * @param string $message
     * @return string
     */
    private function reason(string $message): string
    {
        $known = [
            'getaddrinfo' => 'the server name is unknown (check the spelling of the server)',
            'not known' => 'the server name is unknown (check the spelling of the server)',
            'servname' => 'the server name is unknown (check the spelling of the server)',
            'refused' => 'the server refused the connection (check the port and that SFTP runs there)',
            'timed out' => 'the server did not answer in time (check the server, the port and firewalls)',
            'no route' => 'the server cannot be reached from this shop (network or firewall)',
            'unreachable' => 'the server cannot be reached from this shop (network or firewall)',
        ];
        foreach ($known as $needle => $reason) {
            if (stripos($message, $needle) !== false) {
                return $reason;
            }
        }
        // Without the library's own "Cannot connect to host:22. Error 0." prefix, which repeats the label.
        $plain = trim((string) preg_replace('/^Cannot connect to \S+\s*(Error \d+\.\s*)?/i', '', $message));

        return $plain !== '' ? $plain : $message;
    }

    /**
     * @inheritDoc
     */
    public function listFiles(string $path): array
    {
        $list = $this->sftp()->rawlist($path === '' ? '.' : $path);
        if (!is_array($list)) {
            throw new PipelineException(sprintf('The folder %s cannot be read on %s.', $path, $this->label));
        }
        $files = [];
        foreach ($list as $name => $attributes) {
            if (is_array($attributes) && ($attributes['type'] ?? null) === 1) {
                $files[(string) $name] = isset($attributes['mtime']) ? (int) $attributes['mtime'] : null;
            }
        }

        return $files;
    }

    /**
     * @inheritDoc
     */
    public function download(string $remote, string $local): void
    {
        if ($this->sftp()->get($remote, $local) === false) {
            throw new PipelineException(sprintf('%s could not be downloaded from %s.', $remote, $this->label));
        }
    }

    /**
     * @inheritDoc
     */
    public function upload(string $local, string $remote): void
    {
        if (!$this->sftp()->put($remote, $local, SFTP::SOURCE_LOCAL_FILE)) {
            throw new PipelineException(sprintf('%s could not be uploaded to %s.', $remote, $this->label));
        }
    }

    /**
     * @inheritDoc
     */
    public function move(string $from, string $to): void
    {
        $sftp = $this->sftp();
        $folder = substr($to, 0, (int) strrpos($to, '/'));
        if ($folder !== '' && !$sftp->is_dir($folder)) {
            $sftp->mkdir($folder, -1, true);
        }
        if ($sftp->file_exists($to)) {
            $to .= '-' . gmdate('Ymd-His');
        }
        if (!$sftp->rename($from, $to)) {
            throw new PipelineException(sprintf('%s could not be moved to %s on %s.', $from, $to, $this->label));
        }
    }

    /**
     * @inheritDoc
     */
    public function delete(string $remote): void
    {
        if (!$this->sftp()->delete($remote, false)) {
            throw new PipelineException(sprintf('%s could not be deleted on %s.', $remote, $this->label));
        }
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        $this->sftp?->disconnect();
        $this->sftp = null;
    }

    /**
     * @return SFTP
     */
    private function sftp(): SFTP
    {
        if ($this->sftp === null) {
            throw new PipelineException('Not connected.');
        }

        return $this->sftp;
    }
}
