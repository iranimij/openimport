<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Missing\Fake;

use Iranimij\OpenImport\Model\Missing\MissingScope;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;

/**
 * Products of a shop without a database: SKU => [enabled, in stock].
 */
class FakeMissingScope extends MissingScope
{
    /**
     * @var array<int, array{0: array<string, string>, 1: string}> scopes and narrowings asked for
     */
    public array $asked = [];

    /**
     * @param array<string, array{0: bool, 1: bool}> $products
     */
    // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
    public function __construct(private readonly array $products)
    {
    }

    /**
     * @inheritDoc
     */
    public function check(array $scope): void
    {
        if (($scope['type'] ?? '') === 'website' && ($scope['website'] ?? '') !== 'base') {
            throw new PipelineException(sprintf('Products missing from the file: there is no website "%s".', $scope['website'] ?? ''));
        }
    }

    /**
     * @inheritDoc
     */
    public function products(array $scope, string $only): array
    {
        $this->asked[] = [$scope, $only];
        $skus = [];
        foreach ($this->products as $sku => [$enabled, $inStock]) {
            if (($only === 'enabled' && !$enabled) || ($only === 'in_stock' && !$inStock)) {
                continue;
            }
            $skus[] = $sku;
        }

        return ['skus' => $skus, 'total' => count($this->products)];
    }
}
