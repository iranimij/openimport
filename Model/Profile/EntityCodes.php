<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Profile;

use Iranimij\OpenImport\Model\Entity\EntityRegistry;
use Magento\ImportExport\Model\Export\ConfigInterface as ExportConfig;
use Magento\ImportExport\Model\Import\ConfigInterface as ImportConfig;

/**
 * Entity codes Magento knows for import and export (import.xml / export.xml, OpenImport's own included).
 */
class EntityCodes
{
    /**
     * @param ImportConfig $importConfig
     * @param ExportConfig $exportConfig
     */
    public function __construct(
        private readonly ImportConfig $importConfig,
        private readonly ExportConfig $exportConfig
    ) {
    }

    /**
     * @param string $direction import or export
     * @return string[]
     */
    public function codes(string $direction): array
    {
        $entities = $direction === 'export' ? $this->exportConfig->getEntities() : $this->importConfig->getEntities();
        $codes = array_map('strval', array_keys($entities));
        if ($direction !== 'export' && in_array(EntityRegistry::PRODUCT, $codes, true)) {
            // OpenImport's own entity, imported through core's product import.
            $codes[] = EntityRegistry::STOCK_PRICE;
        }
        if ($direction === 'export') {
            // OpenImport's own order export (core has none).
            $codes[] = EntityRegistry::ORDER;
        }
        // OpenImport's own category import and export (core has none).
        $codes[] = EntityRegistry::CATEGORY;
        $codes[] = EntityRegistry::ATTRIBUTE;

        return $codes;
    }
}
