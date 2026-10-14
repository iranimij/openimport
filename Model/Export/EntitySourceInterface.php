<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Export;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;

/**
 * Where an export reads its rows: Magento's own export entities, OpenImport's order export, or anything
 * registered in di.xml (EntitySourcePool argument "sources", keyed by entity code; the rest goes to core).
 *
 * @api
 */
interface EntitySourceInterface
{
    /**
     * Reads every row of the entity, pushing them one by one (memory stays flat).
     *
     * @param ProfileInterface $profile
     * @param callable $onHeader receives string[] column names, once, before the first row
     * @param callable $onRow receives array<string, mixed> column => value per row
     * @return void
     * @throws PipelineException
     */
    public function read(ProfileInterface $profile, callable $onHeader, callable $onRow): void;

    /**
     * The columns the entity can have, for the mapping editor (a best effort; the file's header is what read()
     * says).
     *
     * @param string $entity
     * @return string[]
     */
    public function columns(string $entity): array;
}
