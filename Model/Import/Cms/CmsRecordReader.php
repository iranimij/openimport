<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Cms;

use Iranimij\OpenImport\Model\Entity\RecordReaderInterface;

/**
 * What the shop's CMS pages or blocks look like now (by identifier), for the dry-run preview and the safety threshold.
 */
class CmsRecordReader implements RecordReaderInterface
{
    /**
     * @param CmsRecords $records
     * @param string $kind page or block
     */
    public function __construct(
        private readonly CmsRecords $records,
        private readonly string $kind = CmsRecords::PAGE
    ) {
    }

    /**
     * @inheritDoc
     */
    public function existing(array $identifiers): array
    {
        $existing = [];
        foreach ($identifiers as $identifier) {
            if ($this->records->find($this->kind, (string) $identifier, []) !== null) {
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
            $model = $this->records->find($this->kind, (string) $identifier, []);
            if ($model === null) {
                continue;
            }
            $values = [];
            foreach ($columns as $column) {
                if ($column === 'identifier') {
                    continue;
                }
                if ($column === 'stores') {
                    $values[$column] = implode(',', array_map(
                        fn (int $id): string => $this->records->storeCode($id),
                        $this->records->storesOf($model)
                    ));
                    continue;
                }
                $value = $model->getData($column);
                $values[$column] = $value === null ? '' : (string) $value;
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
