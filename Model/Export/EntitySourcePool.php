<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Export;

/**
 * Picks the source for an entity code: an own one from di.xml, else Magento's export entity of that code.
 */
class EntitySourcePool
{
    /**
     * @param CoreEntitySource $core
     * @param array<string, EntitySourceInterface> $sources entity code => source
     */
    public function __construct(
        private readonly CoreEntitySource $core,
        private readonly array $sources = []
    ) {
    }

    /**
     * @param string $entity
     * @return EntitySourceInterface
     */
    public function get(string $entity): EntitySourceInterface
    {
        return $this->sources[$entity] ?? $this->core;
    }
}
