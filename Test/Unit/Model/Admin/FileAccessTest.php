<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Admin;

use Iranimij\OpenImport\Model\Admin\FileAccess;
use Magento\Framework\Session\Storage;
use Magento\Framework\AuthorizationInterface;
use PHPUnit\Framework\TestCase;

class FileAccessTest extends TestCase
{
    private function access(bool $allowed, Storage $session): FileAccess
    {
        $authorization = new class ($allowed) implements AuthorizationInterface {
            /**
             * @param bool $allowed
             */
            public function __construct(private readonly bool $allowed)
            {
            }

            /**
             * @param string $resource
             * @param string|null $privilege
             * @return bool
             */
            public function isAllowed($resource, $privilege = null)
            {
                return $this->allowed && $resource === FileAccess::RESOURCE;
            }
        };

        return new FileAccess($authorization, $session);
    }

    private function session(): Storage
    {
        return new Storage('openimport_test');
    }

    public function testFileContentsNeedTheDownloadPermissionOrOwnUpload(): void
    {
        $session = $this->session();
        $viewer = $this->access(false, $session);
        self::assertFalse($viewer->canSee('abc.csv'), 'a stored file of someone else');

        $viewer->grant('abc.csv');
        self::assertTrue($viewer->canSee('abc.csv'), 'a file this admin uploaded');
        self::assertFalse($viewer->canSee('other.csv'));

        self::assertTrue($this->access(true, $this->session())->canSee('other.csv'), 'allowed to download run files');
    }
}
