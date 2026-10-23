<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Shipment;

use Iranimij\OpenImport\Model\Entity\RecordReaderInterface;

/**
 * Shipments are always new records: the preview counts every row as "new", nothing is compared with the shop
 * (what is left to ship is checked by the importer, also on a dry run).
 */
class ShipmentRecordReader implements RecordReaderInterface
{
    /**
     * @inheritDoc
     */
    public function existing(array $identifiers): array
    {
        return [];
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
