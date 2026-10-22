<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Execution\Source\Remote;

use Iranimij\OpenImport\Model\Execution\Source\Remote\RemoteSettings;
use Iranimij\OpenImport\Model\Execution\Source\Remote\SftpClient;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use PHPUnit\Framework\TestCase;

/**
 * A server that cannot be reached is named once, with the reason in plain words (no PHP network internals).
 */
class SftpClientTest extends TestCase
{
    public function testAnUnknownHostIsSaidPlainly(): void
    {
        // ".invalid" never resolves (RFC 2606), with or without a network.
        $settings = new RemoteSettings('sftp.example.invalid', 22, 'qa', 'secret', timeout: 5);
        try {
            (new SftpClient())->connect($settings);
            self::fail('the connection cannot work');
        } catch (PipelineException $exception) {
            $message = $exception->getMessage();
            self::assertStringContainsString('the server name is unknown', $message);
            self::assertStringNotContainsString('php_network_getaddresses', $message);
            self::assertSame(1, substr_count($message, 'sftp.example.invalid'), $message);
        }
    }
}
