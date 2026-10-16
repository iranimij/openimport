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

    public function testS3(): void
    {
        $builder = new RemoteSettingsBuilder(new Secrets(new FakeEncryptor()));

        $aws = $builder->build([
            'region' => 'eu-central-1',
            'bucket' => 'shop-feeds',
            'access_key' => 'AKIA',
            'secrets' => ['secret_key' => 'enc(s3cret)'],
        ], 's3');
        self::assertSame('s3.eu-central-1.amazonaws.com', $aws->host);
        self::assertSame(443, $aws->port);
        self::assertSame('AKIA', $aws->username);
        self::assertSame('s3cret', $aws->password);
        self::assertSame([
            'endpoint' => 'https://s3.eu-central-1.amazonaws.com',
            'region' => 'eu-central-1',
            'bucket' => 'shop-feeds',
            'path_style' => '0',
            'label' => 'bucket shop-feeds (s3.eu-central-1.amazonaws.com)',
        ], $aws->options);
        self::assertSame('bucket shop-feeds (s3.eu-central-1.amazonaws.com)', $aws->label());

        $minio = $builder->build(['endpoint' => 'http://minio:9000/', 'bucket' => 'b1', 'path_style' => '1'], 's3');
        self::assertSame(['minio', 9000, 'us-east-1', '1', 'http://minio:9000'], [
            $minio->host, $minio->port, $minio->options['region'], $minio->options['path_style'], $minio->options['endpoint'],
        ]);

        try {
            $builder->build(['endpoint' => 'ftp://x', 'bucket' => 'b'], 's3');
            self::fail('only web addresses');
        } catch (PipelineException $exception) {
            self::assertStringStartsWith('The endpoint must be a web address', $exception->getMessage());
        }
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage('Enter the bucket.');
        $builder->build(['region' => 'eu-west-1'], 's3');
    }

    public function testGoogleDrive(): void
    {
        $builder = new RemoteSettingsBuilder(new Secrets(new FakeEncryptor()));

        $service = $builder->build([
            'auth_google' => 'service_account',
            'account' => 'importer@project.iam.gserviceaccount.com',
            'as_sheet' => '1',
            'secrets' => ['service_account_json' => 'enc({"type":"service_account"})'],
        ], 'google_drive');
        self::assertSame('service_account', $service->options['auth']);
        self::assertSame('{"type":"service_account"}', $service->options['service_account_json']);
        self::assertSame('1', $service->options['as_sheet']);
        self::assertSame('Google Drive (importer@project.iam.gserviceaccount.com)', $service->label());
        self::assertSame(60, $service->timeout);

        $user = $builder->build([
            'auth_google' => 'oauth',
            'client_id' => ' c-1 ',
            'secrets' => ['client_secret' => 'enc(cs)', 'refresh_token' => 'enc(rt)'],
        ], 'google_drive');
        self::assertSame(['oauth', 'c-1', 'cs', 'rt', '0', 'Google Drive'], [
            $user->options['auth'], $user->options['client_id'], $user->options['client_secret'],
            $user->options['refresh_token'], $user->options['as_sheet'], $user->label(),
        ]);
    }
}
