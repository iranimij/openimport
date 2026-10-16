<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Image\Fake;

use Iranimij\OpenImport\Model\Image\ImageColumns;

/**
 * The standard picture columns and one custom image attribute, without a database.
 */
class FakeImageColumns extends ImageColumns
{
    // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
    public function __construct()
    {
    }

    /**
     * @inheritDoc
     */
    public function single(): array
    {
        return ['base_image', 'small_image', 'thumbnail_image', 'swatch_image', 'hover_image'];
    }
}
