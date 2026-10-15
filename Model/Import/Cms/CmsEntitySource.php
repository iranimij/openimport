<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Cms;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Export\EntitySourceInterface;

/**
 * CMS page or block export: one row per record, store views as codes ("all" for every store view). The file imports
 * back as it is.
 */
class CmsEntitySource implements EntitySourceInterface
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
    public function read(ProfileInterface $profile, callable $onHeader, callable $onRow): void
    {
        $columns = $this->columns($profile->getEntity());
        $onHeader($columns);
        foreach ($this->records->rows($this->kind, $columns) as $row) {
            $onRow($row);
        }
    }

    /**
     * @inheritDoc
     */
    public function finish(ProfileInterface $profile, int $runId): void
    {
    }

    /**
     * @inheritDoc
     */
    public function columns(string $entity): array
    {
        return $this->kind === CmsRecords::PAGE ? CmsImporter::PAGE_COLUMNS : CmsImporter::BLOCK_COLUMNS;
    }
}
