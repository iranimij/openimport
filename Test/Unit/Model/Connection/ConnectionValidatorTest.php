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
        return new ConnectionValidator(new ConnectionTypes(['sftp' => 'SFTP', 'ftp' => 'FTP']));
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
            ['The name is longer than 255 characters.', 'Choose what the connection is for (SFTP, FTP).'],
            $this->validator()->validate(new ConnectionStub(null, str_repeat('x', 256), 's3'))
        );
    }
}
