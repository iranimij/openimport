<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\UrlKey\Fake;

use Iranimij\OpenImport\Model\UrlKey\UrlKeyOwners;

/**
 * URL keys products of the shop have, without a database.
 */
class FakeUrlKeyOwners extends UrlKeyOwners
{
    /**
     * @param array<string, string> $owners URL key => SKU
     */
    // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
    public function __construct(private readonly array $owners = [])
    {
    }

    /**
     * @inheritDoc
     */
    public function owners(array $keys): array
    {
        return array_intersect_key($this->owners, array_flip($keys));
    }
}
