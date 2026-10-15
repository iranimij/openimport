<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Config\Source;

use Iranimij\OpenImport\Model\Entity\EntityRegistry;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\ImportExport\Model\Export\ConfigInterface as ExportConfig;
use Magento\ImportExport\Model\Import\ConfigInterface as ImportConfig;

/**
 * What a profile imports or exports, in plain names. Options carry their direction for the form.
 */
class Entity implements OptionSourceInterface
{
    private const NAMES = [
        'catalog_product' => 'Products',
        EntityRegistry::STOCK_PRICE => 'Stock & prices (existing products)',
        'advanced_pricing' => 'Advanced pricing (tier prices)',
        'stock_sources' => 'Stock per source (MSI)',
        'customer' => 'Customers',
        'customer_composite' => 'Customers with addresses',
        'customer_address' => 'Customer addresses',
        EntityRegistry::ORDER => 'Orders',
        EntityRegistry::CATEGORY => 'Categories',
    ];

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
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        $options = [];
        $import = $this->importConfig->getEntities();
        if (isset($import['catalog_product'])) {
            $import[EntityRegistry::STOCK_PRICE] = ['label' => self::NAMES[EntityRegistry::STOCK_PRICE]];
        }
        $import[EntityRegistry::CATEGORY] = ['label' => self::NAMES[EntityRegistry::CATEGORY]];
        $export = $this->exportConfig->getEntities();
        $export[EntityRegistry::ORDER] = ['label' => self::NAMES[EntityRegistry::ORDER]];
        $export[EntityRegistry::CATEGORY] = ['label' => self::NAMES[EntityRegistry::CATEGORY]];
        foreach (['import' => $import, 'export' => $export] as $direction => $entities) {
            foreach ($entities as $code => $entity) {
                $label = self::NAMES[$code] ?? (string) ($entity['label'] ?? $code);
                $options[] = [
                    'value' => (string) $code,
                    'label' => __($label),
                    'direction' => $direction,
                ];
            }
        }

        return $options;
    }
}
