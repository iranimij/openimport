<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Connection;

use Iranimij\OpenImport\Model\Connection\ConnectionTypes;
use Iranimij\OpenImport\Model\Connection\ConnectionValidator;
use PHPUnit\Framework\TestCase;

class ConnectionValidatorTest extends TestCase
{
    private function validator(): ConnectionValidator
    {
        return new ConnectionValidator(new ConnectionTypes(['sftp' => 'SFTP', 'ftp' => 'FTP', 's3' => 'S3', 'google_drive' => 'Google']));
    }

    public function testAValidConnection(): void
    {
        self::assertSame([], $this->validator()->validate(
            new ConnectionStub(null, 'Supplier', 'sftp', ['host' => 'sftp.example.com', 'username' => 'shop', 'port' => '22'])
        ));
    }

    public function testEveryProblemIsListed(): void
    {
        self::assertSame(
            [
                'Give the connection a name.',
                'Enter the server (host name).',
                'Enter the user name for the server.',
                'The port must be a number between 1 and 65535.',
                'Log in with a password or a private key.',
            ],
            $this->validator()->validate(new ConnectionStub(null, ' ', 'ftp', ['port' => '0', 'auth_sftp' => 'token']))
        );
        self::assertSame(
            ['The name is longer than 255 characters.', 'Choose what the connection is for (SFTP, FTP, S3, Google).'],
            $this->validator()->validate(new ConnectionStub(null, str_repeat('x', 256), 'dropbox'))
        );
    }

    public function testS3NeedsBucketKeysAndARegionOrEndpoint(): void
    {
        self::assertSame([], $this->validator()->validate(new ConnectionStub(null, 'Feeds', 's3', [
            'region' => 'eu-central-1', 'bucket' => 'shop-feeds', 'access_key' => 'AKIA',
            'secrets' => ['secret_key' => 'enc(x)'],
        ])));
        self::assertSame([], $this->validator()->validate(new ConnectionStub(null, 'R2', 's3', [
            'endpoint' => 'https://abc.r2.cloudflarestorage.com', 'bucket' => 'feeds', 'access_key' => 'k',
            'secrets' => ['secret_key' => 'enc(x)'],
        ])));
        self::assertSame(
            [
                'Enter the region of the bucket (e.g. eu-central-1), or the endpoint of an S3-compatible service.',
                'Enter the bucket.',
                'Enter the access key.',
                'Enter the secret key.',
            ],
            $this->validator()->validate(new ConnectionStub(null, 'Empty', 's3'))
        );
        self::assertSame(
            [
                'The endpoint must be a web address such as https://s3.eu-central-1.amazonaws.com.',
                'A bucket name has 3 to 63 lower-case letters, digits, dots and hyphens.',
            ],
            $this->validator()->validate(new ConnectionStub(null, 'Bad', 's3', [
                'endpoint' => 'minio:9000/path', 'bucket' => 'Shop_Feeds', 'access_key' => 'k',
                'secrets' => ['secret_key' => 'enc(x)'],
            ]))
        );
    }

    public function testGoogleNeedsAKeyOrAnOAuthClient(): void
    {
        self::assertSame([], $this->validator()->validate(new ConnectionStub(null, 'Drive', 'google_drive', [
            'account' => 'importer@project.iam.gserviceaccount.com',
            'secrets' => ['service_account_json' => 'enc({})'],
        ])));
        self::assertSame(
            ['Paste the JSON key of a Google service account (Google Cloud console › IAM › Service accounts › Keys).'],
            $this->validator()->validate(new ConnectionStub(null, 'Drive', 'google_drive', ['auth_google' => 'service_account']))
        );
        self::assertSame([], $this->validator()->validate(new ConnectionStub(null, 'Drive', 'google_drive', [
            'auth_google' => 'oauth', 'client_id' => 'c-1', 'secrets' => ['client_secret' => 'enc(s)'],
        ])));
        self::assertSame(
            [
                'Enter the client ID of your OAuth client (Google Cloud console › APIs & Services › Credentials).',
                'Enter the client secret of your OAuth client.',
            ],
            $this->validator()->validate(new ConnectionStub(null, 'Drive', 'google_drive', ['auth_google' => 'oauth']))
        );
    }
}
