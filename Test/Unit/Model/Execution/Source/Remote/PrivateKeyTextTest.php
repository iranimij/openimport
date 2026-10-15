<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Execution\Source\Remote;

use Iranimij\OpenImport\Model\Execution\Source\Remote\PrivateKeyText;
use PHPUnit\Framework\TestCase;

class PrivateKeyTextTest extends TestCase
{
    private const OPENSSH = "-----BEGIN OPENSSH PRIVATE KEY-----\n"
        . "b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW\n"
        . "QyNTUxOQAAACBbq2pGvCwQb0xDVrJ2DCvcI7z9kMg3GfBoaT+wYhIa3QAAAJgAAAAAAAAA\n"
        . "AAAAAAtzc2gtZWQyNTUxOQAAACBbq2pGvCwQb0xDVrJ2DCvcI7z9kMg3GfBoaT+wYhIa3Q\n"
        . "-----END OPENSSH PRIVATE KEY-----\n";

    public function testKeepsAWellFormedKey(): void
    {
        $normalized = (new PrivateKeyText())->normalize(self::OPENSSH);

        self::assertStringStartsWith("-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNz", $normalized);
        self::assertTrue(str_ends_with($normalized, "\n-----END OPENSSH PRIVATE KEY-----\n"));
        self::assertSame(
            str_replace("\n", '', self::OPENSSH),
            str_replace("\n", '', $normalized)
        );
    }

    public function testRepairsAKeyWithoutLineBreaks(): void
    {
        $text = new PrivateKeyText();
        $joined = str_replace("\n", '', self::OPENSSH);
        $spaced = str_replace("\n", ' ', self::OPENSSH);

        self::assertSame($text->normalize(self::OPENSSH), $text->normalize($joined));
        self::assertSame($text->normalize(self::OPENSSH), $text->normalize($spaced));
        self::assertSame($text->normalize(self::OPENSSH), $text->normalize(str_replace("\n", "\r\n", self::OPENSSH)));
    }

    public function testKeepsTheHeadersOfAnEncryptedPemKey(): void
    {
        $pem = "-----BEGIN RSA PRIVATE KEY-----\nProc-Type: 4,ENCRYPTED\nDEK-Info: AES-128-CBC,0123456789ABCDEF0123456789ABCDEF\n\n"
            . "QUJDRA==\n-----END RSA PRIVATE KEY-----";

        self::assertSame(
            "-----BEGIN RSA PRIVATE KEY-----\nProc-Type: 4,ENCRYPTED\nDEK-Info: AES-128-CBC,0123456789ABCDEF0123456789ABCDEF\n\n"
            . "QUJDRA==\n-----END RSA PRIVATE KEY-----\n",
            (new PrivateKeyText())->normalize(str_replace("\n", ' ', $pem))
        );
    }

    public function testLeavesOtherTextAlone(): void
    {
        self::assertSame('not a key', (new PrivateKeyText())->normalize("  not a key\n"));
    }
}
