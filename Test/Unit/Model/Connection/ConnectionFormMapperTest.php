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

    public function testAServiceAccountKeyNamesTheAccountAndAnotherLoginForgetsTheOldOne(): void
    {
        $mapper = new ConnectionFormMapper(new Secrets(new FakeEncryptor()));
        $key = (string) json_encode(['type' => 'service_account', 'client_email' => 'importer@p.iam.gserviceaccount.com']);
        $connection = $mapper->fromForm([
            'name' => 'Drive',
            'type' => 'google_drive',
            'settings' => ['auth_google' => 'service_account', 'service_account_json' => $key, 'account' => 'forged'],
        ], new ConnectionStub());
        self::assertSame('importer@p.iam.gserviceaccount.com', $connection->getSettings()['account']);
        self::assertSame('importer@p.iam.gserviceaccount.com', $mapper->toForm($connection)['settings']['account']);
        self::assertSame('1', $mapper->toForm($connection)['settings']['service_account_json_stored']);

        $kept = $mapper->fromForm(['name' => 'Drive', 'type' => 'google_drive', 'settings' => ['auth_google' => 'service_account']], $connection);
        self::assertSame('importer@p.iam.gserviceaccount.com', $kept->getSettings()['account'], 'kept without a new key');

        $user = $mapper->fromForm([
            'name' => 'Drive',
            'type' => 'google_drive',
            'settings' => ['auth_google' => 'oauth', 'client_id' => 'c-1', 'client_secret' => 's'],
        ], $kept->setSettings($kept->getSettings() + ['secrets' => []]));
        self::assertArrayNotHasKey('account', $user->getSettings(), 'another kind of login');
    }
}
