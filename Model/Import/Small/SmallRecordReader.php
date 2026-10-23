<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Small;

use Iranimij\OpenImport\Model\Entity\RecordReaderInterface;

/**
 * What the shop's records of a small entity look like now, through the entity's export source.
 */
class SmallRecordReader implements RecordReaderInterface
{
    /**
     * @param SmallSourceInterface $source
     * @param string[] $identifier the identifier columns (left out of the current values)
     */
    public function __construct(
        private readonly SmallSourceInterface $source,
        private readonly array $identifier = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function existing(array $identifiers): array
    {
        $existing = [];
        foreach ($identifiers as $identifier) {
            if ($this->source->find((string) $identifier) !== null) {
                $existing[mb_strtolower((string) $identifier)] = true;
            }
        }

        return $existing;
    }

    /**
     * @inheritDoc
     */
    public function current(array $identifiers, array $columns, ?string $storeCode = null): array
    {
        $current = [];
        foreach ($identifiers as $identifier) {
            $row = $this->source->find((string) $identifier);
            if ($row === null) {
                continue;
            }
            $values = [];
            foreach ($columns as $column) {
                if (in_array($column, $this->identifier, true)) {
                    continue;
                }
                $values[$column] = $row[$column] ?? '';
            }
            $current[mb_strtolower((string) $identifier)] = $values;
        }

        return $current;
    }

    /**
     * @inheritDoc
     */
    public function total(): int
    {
        return $this->source->count();
    }
}
