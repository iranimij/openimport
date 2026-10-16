<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Connection;

use Iranimij\OpenImport\Model\Connection\ConnectionConfig;
use Iranimij\OpenImport\Model\Connection\ConnectionTypes;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use PHPUnit\Framework\TestCase;

class ConnectionConfigTest extends TestCase
{
    private function config(): ConnectionConfig
    {
        return new ConnectionConfig(
            new FakeConnectionRepository([
                new ConnectionStub(3, 'Supplier SFTP', 'sftp', [
                    'host' => 'sftp.example.com',
                    'port' => '2222',
                    'username' => 'shop',
                    'auth_sftp' => 'key',
                    'secrets' => ['private_key' => 'enc(KEY)'],
                    'unrelated' => 'not copied',
                ]),
            ]),
            new ConnectionTypes(['sftp' => 'SFTP', 'ftp' => 'FTP'])
        );
    }

    public function testWithoutAConnectionTheProfileSettingsStay(): void
    {
        $own = ['host' => 'own.example.com', 'path' => '/in', 'connection_id' => ''];

        self::assertSame($own, $this->config()->resolve($own, 'sftp'));
        self::assertSame(0, $this->config()->selected($own));
        self::assertSame(0, $this->config()->selected(['connection_id' => 'abc']));
    }

    public function testTheConnectionGivesServerAndLoginTheProfileKeepsItsFolder(): void
    {
        $resolved = $this->config()->resolve([
            'connection_id' => '3',
            'host' => 'stale.example.com',
            'password' => 'never used',
            'secrets' => ['password' => 'enc(stale)'],
            'path' => '/in',
            'pattern' => '*.csv',
        ], 'sftp');

        self::assertSame([
            'connection_id' => '3',
            'password' => 'never used',
            'path' => '/in',
            'pattern' => '*.csv',
            'host' => 'sftp.example.com',
            'port' => '2222',
            'username' => 'shop',
            'auth_sftp' => 'key',
            'secrets' => ['private_key' => 'enc(KEY)'],
        ], $resolved);
    }

    public function testAConnectionOfAnotherTypeOrAMissingOneIsAPlainError(): void
    {
        self::assertSame(
            'The saved connection "Supplier SFTP" is for SFTP, not for FTP.',
            $this->config()->problem(['connection_id' => 3], 'ftp')
        );
        self::assertNull($this->config()->problem(['connection_id' => 3], 'sftp'));
        self::assertNull($this->config()->problem([], 'ftp'));
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage('The saved connection 8 no longer exists.');
        $this->config()->resolve(['connection_id' => '8'], 'sftp');
    }

    public function testNamesAndIds(): void
    {
        self::assertSame(3, $this->config()->idByName('supplier sftp'));
        self::assertNull($this->config()->idByName('Nope'));
        self::assertSame('Supplier SFTP', $this->config()->nameById(3));
        self::assertNull($this->config()->nameById(4));
    }
}
