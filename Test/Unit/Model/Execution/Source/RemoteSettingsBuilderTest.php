<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Execution\Source;

use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteSettingsBuilder;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Security\Secrets;
use Iranimij\OpenImport\Test\Unit\Model\Security\FakeEncryptor;
use PHPUnit\Framework\TestCase;

class RemoteSettingsBuilderTest extends TestCase
{
    public function testDefaultsAndSecrets(): void
    {
        $builder = new RemoteSettingsBuilder(new Secrets(new FakeEncryptor()));

        $ftp = $builder->build(['host' => ' ftp.example.com ', 'username' => 'u', 'secrets' => ['password' => 'enc(p)']], 'ftp');
        self::assertSame('ftp.example.com:21', $ftp->label());
        self::assertSame('p', $ftp->password);
        self::assertTrue($ftp->passive);
        self::assertSame(30, $ftp->timeout);

        $sftp = $builder->build([
            'host' => 'sftp.example.com',
            'port' => '2222',
            'auth_sftp' => 'key',
            'timeout' => '1',
            'secrets' => ['private_key' => 'enc(KEY)', 'passphrase' => 'enc(pp)'],
        ], 'sftp');
        self::assertSame(2222, $sftp->port);
        self::assertSame('KEY', $sftp->privateKey);
        self::assertSame('pp', $sftp->passphrase);
        self::assertSame(5, $sftp->timeout);

        $this->expectException(PipelineException::class);
        $builder->build([], 'sftp');
    }
}
