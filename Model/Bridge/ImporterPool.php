<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Bridge;

/**
 * The importer for an entity: OpenImport's own one when registered in di.xml, else core's import engine.
 */
class ImporterPool
{
    /**
     * @param CoreImporter $core
     * @param array<string, ImporterInterface> $importers entity code => importer
     */
    public function __construct(
        private readonly CoreImporter $core,
        private readonly array $importers = []
    ) {
    }

    /**
     * @param string $entity
     * @return ImporterInterface
     */
    public function get(string $entity): ImporterInterface
    {
        return $this->importers[$entity] ?? $this->core;
    }

    /**
     * @param string $entity
     * @return bool whether OpenImport writes this entity itself (no core import history, no core indexer calls)
     */
    public function isOwn(string $entity): bool
    {
        return isset($this->importers[$entity]);
    }
}
