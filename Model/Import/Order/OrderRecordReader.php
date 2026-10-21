<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Order;

use Iranimij\OpenImport\Model\Entity\RecordReaderInterface;

/**
 * Orders are never updated by the import: an order number that exists is "existing" (so Only add skips it and
 * the preview counts it as unchanged); nothing is compared field by field.
 */
class OrderRecordReader implements RecordReaderInterface
{
    /**
     * @param OrderRecords $records
     */
    public function __construct(
        private readonly OrderRecords $records
    ) {
    }

    /**
     * @inheritDoc
     */
    public function existing(array $identifiers): array
    {
        $existing = [];
        foreach ($this->records->existingOrders(array_map('strval', $identifiers)) as $incrementId => $true) {
            $existing[mb_strtolower((string) $incrementId)] = true;
        }

        return $existing;
    }

    /**
     * @inheritDoc
     */
    public function current(array $identifiers, array $columns, ?string $storeCode = null): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function total(): int
    {
        return 0;
    }
}
