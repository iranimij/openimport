<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\PriceRule;

use Iranimij\OpenImport\Model\Entity\RecordReaderInterface;

/**
 * What the shop's price rules look like now (by name), for the dry-run preview and the safety threshold.
 */
class PriceRuleRecordReader implements RecordReaderInterface
{
    /**
     * @param PriceRuleRecords $records
     * @param PriceRuleEntitySource $source
     * @param string $kind cart or catalog
     */
    public function __construct(
        private readonly PriceRuleRecords $records,
        private readonly PriceRuleEntitySource $source,
        private readonly string $kind = ConditionTree::CART
    ) {
    }

    /**
     * @inheritDoc
     */
    public function existing(array $identifiers): array
    {
        $existing = [];
        foreach ($identifiers as $identifier) {
            if ($this->records->idsByName($this->kind, (string) $identifier) !== []) {
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
            $ids = $this->records->idsByName($this->kind, (string) $identifier);
            $rule = $ids === [] ? null : $this->records->load($this->kind, $ids[0]);
            if ($rule === null) {
                continue;
            }
            $row = $this->source->row($rule);
            $values = [];
            foreach ($columns as $column) {
                if ($column === 'name') {
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
        return $this->records->count($this->kind);
    }
}
