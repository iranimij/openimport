<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Connection;

use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteClientInterface;
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteSettings;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;

/**
 * A server in memory: remembers the settings it was connected with and the files of every folder.
 */
class FakeRemoteClient implements RemoteClientInterface
{
    /**
     * @var RemoteSettings|null the last login
     */
    public static ?RemoteSettings $connected = null;

    /**
     * @var array<string, array<string, string>> folder => name => content
     */
    public static array $files = [];

    /**
     * @var string a password that is refused ('' = every password works)
     */
    public static string $refuse = '';

    /**
     * @return void
     */
    public static function reset(): void
    {
        self::$connected = null;
        self::$files = [];
        self::$refuse = '';
    }

    /**
     * @inheritDoc
     */
    public function connect(RemoteSettings $settings): void
    {
        if (self::$refuse !== '' && $settings->password === self::$refuse) {
            throw new PipelineException(sprintf('The login to %s was refused.', $settings->label()));
        }
        self::$connected = $settings;
    }

    /**
     * @inheritDoc
     */
    public function listFiles(string $path): array
    {
        return array_map(static fn (): ?int => null, self::$files[$path] ?? []);
    }

    /**
     * @inheritDoc
     */
    public function download(string $remote, string $local): void
    {
        $folder = (string) dirname($remote);
        $folder = $folder === '.' ? '' : $folder;
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        file_put_contents($local, self::$files[$folder][basename($remote)] ?? '');
    }

    /**
     * @inheritDoc
     */
    public function upload(string $local, string $remote): void
    {
        $folder = (string) dirname($remote);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        self::$files[$folder === '.' ? '' : $folder][basename($remote)] = (string) file_get_contents($local);
    }

    /**
     * @inheritDoc
     */
    public function move(string $from, string $to): void
    {
    }

    /**
     * @inheritDoc
     */
    public function delete(string $remote): void
    {
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
    }
}
