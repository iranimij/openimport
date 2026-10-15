<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Bridge;

use Magento\ImportExport\Model\Import\AbstractSource;

/**
 * Writes prepared rows into Magento: core's own import engine for core entities, OpenImport's own importers for
 * entities core has none for (categories, attributes, CMS). Every importer validates all rows first, writes nothing
 * on a dry run, and reports errors against 0-based row positions of the source.
 *
 * @api
 */
interface ImporterInterface
{
    /**
     * @param ImportRequest $request
     * @param AbstractSource $source mapped rows in Magento's column names
     * @return ImportResult
     */
    public function run(ImportRequest $request, AbstractSource $source): ImportResult;
}
