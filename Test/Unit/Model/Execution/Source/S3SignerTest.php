<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Execution\Source;

use Iranimij\OpenImport\Model\Execution\Source\Remote\S3Signer;
use PHPUnit\Framework\TestCase;

/**
 * The examples of AWS's "Signature Calculations for the Authorization Header: Transferring Payload in a Single
 * Chunk (AWS Signature Version 4)" page.
 */
class S3SignerTest extends TestCase
{
    private const ACCESS = 'AKIAIOSFODNN7EXAMPLE';
    private const SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2013-05-24T00:00:00Z');
    }

    public function testGetObject(): void
    {
        $headers = (new S3Signer())->sign(
            'GET',
            'examplebucket.s3.amazonaws.com',
            '/test.txt',
            [],
            ['Range' => 'bytes=0-9'],
            S3Signer::EMPTY_PAYLOAD,
            self::ACCESS,
            self::SECRET,
            'us-east-1',
            $this->now()
        );

        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, '
            . 'SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, '
            . 'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            $headers['Authorization']
        );
        self::assertSame('20130524T000000Z', $headers['x-amz-date']);
        self::assertSame(S3Signer::EMPTY_PAYLOAD, $headers['x-amz-content-sha256']);
        self::assertSame(hash('sha256', ''), S3Signer::EMPTY_PAYLOAD);
    }

    public function testPutObject(): void
    {
        $body = 'Welcome to Amazon S3.';
        $headers = (new S3Signer())->sign(
            'PUT',
            'examplebucket.s3.amazonaws.com',
            '/test$file.text',
            [],
            ['Date' => 'Fri, 24 May 2013 00:00:00 GMT', 'x-amz-storage-class' => 'REDUCED_REDUNDANCY'],
            hash('sha256', $body),
            self::ACCESS,
            self::SECRET,
            'us-east-1',
            $this->now()
        );

        self::assertStringEndsWith(
            'SignedHeaders=date;host;x-amz-content-sha256;x-amz-date;x-amz-storage-class, '
            . 'Signature=98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd',
            $headers['Authorization']
        );
    }

    public function testListObjects(): void
    {
        $signer = new S3Signer();
        $headers = $signer->sign(
            'GET',
            'examplebucket.s3.amazonaws.com',
            '/',
            ['prefix' => 'J', 'max-keys' => '2'],
            [],
            S3Signer::EMPTY_PAYLOAD,
            self::ACCESS,
            self::SECRET,
            'us-east-1',
            $this->now()
        );

        self::assertStringEndsWith(
            'Signature=34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7',
            $headers['Authorization']
        );
        self::assertSame('max-keys=2&prefix=J', $signer->encodeQuery(['prefix' => 'J', 'max-keys' => '2']));
        self::assertSame('/b/my%20file%2B%C3%A4.csv', $signer->encodePath('/b/my file+ä.csv'));
        self::assertSame('a=x%2Fy%20z&list-type=2', $signer->encodeQuery(['list-type' => '2', 'a' => 'x/y z']));
    }
}
