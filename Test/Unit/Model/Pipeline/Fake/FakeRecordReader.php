<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Pipeline\Fake;

use Iranimij\OpenImport\Model\Entity\RecordReaderInterface;

/**
 * A shop in memory: identifier => default values, plus store values.
 */
class FakeRecordReader implements RecordReaderInterface
{
    /**
     * @param array<string, array<string, string>> $records lower-cased identifier => column => value
     * @param array<string, array<string, array<string, string>>> $storeValues store => identifier => values
     * @param int $total
     */
    public function __construct(
        public array $records = [],
        public array $storeValues = [],
        public int $total = 100
    ) {
    }

    /**
     * @inheritDoc
     */
    public function existing(array $identifiers): array
    {
        $existing = [];
        foreach ($identifiers as $identifier) {
            if (isset($this->records[mb_strtolower($identifier)])) {
                $existing[mb_strtolower($identifier)] = true;
            }
        }

        return $existing;
    }

    /**
     * @inheritDoc
     */
    public function current(array $identifiers, array $columns, ?string $storeCode = null): array
    {
        $values = [];
        foreach ($identifiers as $identifier) {
            $key = mb_strtolower($identifier);
            if (isset($this->records[$key])) {
                $source = $storeCode === null ? $this->records[$key] : ($this->storeValues[$storeCode][$key] ?? $this->records[$key]);
                $values[$key] = array_intersect_key($source, array_flip($columns));
            }
        }

        return $values;
    }

    /**
     * @inheritDoc
     */
    public function total(): int
    {
        return $this->total;
    }
}
