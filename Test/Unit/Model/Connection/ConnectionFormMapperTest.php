<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Connection;

use Iranimij\OpenImport\Model\Admin\ConnectionFormMapper;
use Iranimij\OpenImport\Model\Security\Secrets;
use Iranimij\OpenImport\Test\Unit\Model\Security\FakeEncryptor;
use PHPUnit\Framework\TestCase;

class ConnectionFormMapperTest extends TestCase
{
    public function testSecretsAreEncryptedNeverShownAndKeptWhenLeftEmpty(): void
    {
        $mapper = new ConnectionFormMapper(new Secrets(new FakeEncryptor()));
        $connection = $mapper->fromForm([
            'name' => ' Supplier ',
            'type' => 'sftp',
            'settings' => [
                'host' => ' sftp.example.com ',
                'username' => 'shop',
                'password' => 'pa55',
                'password_stored' => '0',
                'path' => '/not/a/login/setting',
                'secrets' => ['password' => 'smuggled'],
            ],
        ], new ConnectionStub());

        self::assertSame('Supplier', $connection->getName());
        self::assertSame(
            ['host' => 'sftp.example.com', 'username' => 'shop', 'secrets' => ['password' => 'enc(pa55)']],
            $connection->getSettings()
        );

        $form = $mapper->toForm($connection);
        self::assertSame('sftp.example.com', $form['settings']['host']);
        self::assertSame('1', $form['settings']['password_stored']);
        self::assertSame('password', $form['settings']['auth_sftp']);
        self::assertArrayNotHasKey('secrets', $form['settings']);
        self::assertStringNotContainsString('pa55', (string) json_encode($form));

        $again = $mapper->fromForm(['name' => 'Supplier', 'type' => 'sftp', 'settings' => ['host' => 'h', 'password' => '']], $connection);
        self::assertSame(['password' => 'enc(pa55)'], $again->getSettings()['secrets'], 'an empty field keeps the password');
    }
}
