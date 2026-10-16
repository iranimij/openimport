<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Connection;

use Iranimij\OpenImport\Model\Connection\ConnectionTester;
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteClientFactory;
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteClientInterface;
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteSettings;
use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteSettingsBuilder;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Security\Secrets;
use Iranimij\OpenImport\Test\Unit\Model\FakeObjectManager;
use Iranimij\OpenImport\Test\Unit\Model\Security\FakeEncryptor;
use PHPUnit\Framework\TestCase;

class ConnectionTesterTest extends TestCase
{
    public function testLogsInReadsTheLoginFolderAndAlwaysCloses(): void
    {
        $client = new class implements RemoteClientInterface {
            public ?RemoteSettings $settings = null;
            public bool $closed = false;

            public function connect(RemoteSettings $settings): void
            {
                if ($settings->password === 'wrong') {
                    throw new PipelineException('Login refused.');
                }
                $this->settings = $settings;
            }

            public function listFiles(string $path): array
            {
                return ['a.csv' => null, 'b.csv' => 1];
            }

            public function download(string $remote, string $local): void
            {
            }

            public function upload(string $local, string $remote): void
            {
            }

            public function move(string $from, string $to): void
            {
            }

            public function delete(string $remote): void
            {
            }

            public function close(): void
            {
                $this->closed = true;
            }
        };
        $tester = new ConnectionTester(
            new RemoteClientFactory(new FakeObjectManager(['fake' => $client]), ['ftp' => 'fake']),
            new RemoteSettingsBuilder(new Secrets(new FakeEncryptor()))
        );

        $connection = new ConnectionStub(1, 'Old FTP', 'ftp', ['host' => 'ftp.example.com', 'secrets' => ['password' => 'enc(pw)']]);
        self::assertSame('Connected to ftp.example.com:21. The login folder has 2 file(s).', $tester->test($connection));
        self::assertSame('pw', $client->settings?->password);
        self::assertTrue($client->closed);

        $client->closed = false;
        try {
            $tester->test($connection->setSettings(['host' => 'ftp.example.com', 'secrets' => ['password' => 'enc(wrong)']]));
            self::fail('a refused login is an error');
        } catch (PipelineException $exception) {
            self::assertSame('Login refused.', $exception->getMessage());
        }
        self::assertTrue($client->closed, 'closed after a failed login too');
    }
}
