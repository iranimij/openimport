<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Pipeline\Step;

/**
 * Row step hooks that do nothing, so a step implements only what it needs.
 */
abstract class AbstractRowStep implements RowStepInterface
{
    /**
     * @inheritDoc
     */
    public function columns(array $columns): array
    {
        return $columns;
    }

    /**
     * @inheritDoc
     */
    public function observe(array $records): void
    {
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock -- nothing to observe by default
    }

    /**
     * @inheritDoc
     */
    public function observeIdentifiers(array $identifiers): void
    {
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock -- nothing to observe by default
    }

    /**
     * @inheritDoc
     */
    public function apply(array $records, \Closure $tick): void
    {
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock -- nothing to change by default
    }

    /**
     * @inheritDoc
     */
    public function finish(\Closure $tick): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function summary(): string
    {
        return '';
    }
}
