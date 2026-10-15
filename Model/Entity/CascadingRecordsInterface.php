<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Entity;

/**
 * A record reader whose deletes take other records with them (a category and its children). The safety threshold
 * and the dry run count what really goes, not the rows of the file.
 *
 * @api
 */
interface CascadingRecordsInterface
{
    /**
     * Number of records a delete of these identifiers removes, each record once.
     *
     * @param string[] $identifiers
     * @return int
     */
    public function affectedByDelete(array $identifiers): int;
}
