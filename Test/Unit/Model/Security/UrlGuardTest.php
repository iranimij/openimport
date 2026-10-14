<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Security;

use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Security\UrlGuard;
use Iranimij\OpenImport\Test\Unit\Model\Pipeline\Fake\FakeConfig;
use PHPUnit\Framework\TestCase;

class UrlGuardTest extends TestCase
{
    /**
     * @param string[] $allowedHosts
     * @return UrlGuard
     */
    private function guard(array $allowedHosts = []): UrlGuard
    {
        $config = new FakeConfig();
        $config->allowedHosts = $allowedHosts;

        return new UrlGuard($config);
    }

    public function testPublicAddresses(): void
    {
        $cases = [
            'public v4' => ['93.184.216.34', true],
            'loopback' => ['127.0.0.1', false],
            'private 10' => ['10.1.2.3', false],
            'private 172' => ['172.16.0.9', false],
            'private 192' => ['192.168.1.1', false],
            'link-local (cloud metadata)' => ['169.254.169.254', false],
            'cgnat' => ['100.64.0.1', false],
            'zero' => ['0.0.0.0', false],
            'public v6' => ['2606:2800:220:1:248:1893:25c8:1946', true],
            'v6 loopback' => ['::1', false],
            'v6 unique local' => ['fd12::1', false],
            'v6 link local' => ['fe80::1', false],
            'v4-mapped private' => ['::ffff:192.168.0.1', false],
            'v4-mapped public' => ['::ffff:93.184.216.34', true],
        ];
        $guard = $this->guard();
        foreach ($cases as $label => [$address, $public]) {
            self::assertSame($public, $guard->isPublic($address), $label);
        }
    }

    public function testLiteralAddressesAndSchemes(): void
    {
        $guard = $this->guard();
        [$parts, $address] = $guard->check('https://93.184.216.34/feed.csv?x=1');
        self::assertSame('93.184.216.34', $address);
        self::assertSame('/feed.csv', $parts['path']);

        foreach ([
            'ftp://example.com/a.csv' => 'Only http and https',
            'http://127.0.0.1/admin' => 'private or local address',
            'http://[::1]/x' => 'private or local address',
            'https://user:pw@93.184.216.34/x' => 'not into the address',
            'not a url' => 'full web address',
            'http://169.254.169.254/latest/meta-data/' => 'private or local address',
        ] as $url => $message) {
            try {
                $guard->check($url);
                self::fail($url . ' must be refused');
            } catch (PipelineException $exception) {
                self::assertStringContainsString($message, $exception->getMessage(), $url);
            }
        }
    }

    public function testAnAllowedHostMayBePrivate(): void
    {
        [, $address] = $this->guard(['192.168.1.20'])->check('http://192.168.1.20:8080/export.csv');

        self::assertSame('192.168.1.20', $address);
    }
}
