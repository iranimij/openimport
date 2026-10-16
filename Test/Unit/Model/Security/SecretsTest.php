<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Security;

use Iranimij\OpenImport\Model\Security\Secrets;
use PHPUnit\Framework\TestCase;

class SecretsTest extends TestCase
{
    public function testSecretsAreStoredEncryptedKeptWhenEmptyAndNeverReturned(): void
    {
        $secrets = new Secrets(new FakeEncryptor());

        $config = $secrets->merge(['host' => 'sftp.example.com', 'password' => 'pa55', 'token' => ''], []);
        self::assertSame(['host' => 'sftp.example.com', 'secrets' => ['password' => 'enc(pa55)']], $config);
        self::assertSame('pa55', $secrets->get($config, 'password'));
        self::assertSame('', $secrets->get($config, 'token'));
        self::assertSame(
            ['password' => true, 'private_key' => false, 'passphrase' => false, 'token' => false, 'secret_key' => false],
            $secrets->stored($config)
        );
        self::assertSame(['host' => 'sftp.example.com'], $secrets->strip($config));

        $again = $secrets->merge(['host' => 'other', 'password' => '', 'token' => 't0k'], $config);
        self::assertSame(['password' => 'enc(pa55)', 'token' => 'enc(t0k)'], $again['secrets'], 'empty keeps the stored password');

        $cleared = $secrets->merge(['password_clear' => '1', 'password' => 'ignored'], $again);
        self::assertSame(['token' => 'enc(t0k)'], $cleared['secrets']);
        self::assertArrayNotHasKey('password_clear', $cleared);

        $posted = $secrets->merge(['secrets' => ['password' => 'smuggled']], []);
        self::assertArrayNotHasKey('secrets', $posted, 'posted secrets are never taken as stored ones');

        self::assertSame($again, $secrets->reencrypt($again));
    }
}
