<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Small;

use Iranimij\OpenImport\Model\Export\EntitySourceInterface;

/**
 * An export source of a small entity that can also answer for one record, for the dry-run preview.
 */
interface SmallSourceInterface extends EntitySourceInterface
{
    /**
     * @param string $identifier the identifier as the pipeline builds it (identifier columns joined by "|")
     * @return array<string, string>|null the record as a file row
     */
    public function find(string $identifier): ?array;

    /**
     * @return int how many records the shop has
     */
    public function count(): int;
}
