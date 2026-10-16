<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Pipeline\Fake;

use Iranimij\OpenImport\Model\Image\ImageFolder;

/**
 * An import images folder that is only a name.
 */
class FakeImageFolder extends ImageFolder
{
    public const PATH = '/srv/magento/var/import/images';

    // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
    public function __construct()
    {
    }

    /**
     * @inheritDoc
     */
    public function absoluteBase(): string
    {
        return self::PATH;
    }
}
