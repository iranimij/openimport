<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Configurable\Fake;

use Iranimij\OpenImport\Model\Configurable\UrlKey;

/**
 * Lower case and hyphens, without transliteration.
 */
class FakeUrlKey extends UrlKey
{
    // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
    public function __construct()
    {
    }

    /**
     * @inheritDoc
     */
    public function format(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
    }
}
