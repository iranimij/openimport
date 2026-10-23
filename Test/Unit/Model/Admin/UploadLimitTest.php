<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Admin;

use Iranimij\OpenImport\Model\Admin\UploadLimit;
use PHPUnit\Framework\TestCase;

class UploadLimitTest extends TestCase
{
    public function testTheSmallerLimitCounts(): void
    {
        self::assertSame(2097152, (new UploadLimit('2M', '8M'))->bytes());
        self::assertSame('2 MB', (new UploadLimit('2M', '8M'))->text());
        self::assertSame('64 MB', (new UploadLimit('1G', '64M'))->text());
        self::assertSame('< 1 MB', (new UploadLimit('512K', '8M'))->text());
        self::assertSame('1.5 MB', (new UploadLimit('1536K', '0'))->text(), 'post_max_size 0 means no limit');
        self::assertSame(0, (new UploadLimit('', '0'))->bytes());
        self::assertSame('', (new UploadLimit('nonsense', ''))->text());
    }
}
