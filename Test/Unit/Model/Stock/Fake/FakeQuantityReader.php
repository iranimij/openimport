<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Stock\Fake;

use Iranimij\OpenImport\Model\Stock\QuantityReader;

/**
 * Quantities without a database.
 */
class FakeQuantityReader extends QuantityReader
{
    public int $reads = 0;

    /**
     * @param array<string, float> $products lower-cased SKU => quantity
     * @param array<string, float> $sources "lower-cased SKU|source" => quantity
     */
    // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
    public function __construct(private readonly array $products = [], private readonly array $sources = [])
    {
    }

    /**
     * @inheritDoc
     */
    public function products(array $skus): array
    {
        $this->reads++;

        return array_intersect_key($this->products, array_flip(array_map('mb_strtolower', $skus)));
    }

    /**
     * @inheritDoc
     */
    public function sources(array $pairs): array
    {
        $this->reads++;

        return $this->sources;
    }
}
