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
    /**
     * Plain names, in the order the form lists them (catalog, customers, sales, content).
     */
    private const NAMES = [
        'catalog_product' => 'Products',
        EntityRegistry::STOCK_PRICE => 'Stock & prices (existing products)',
        'advanced_pricing' => 'Advanced pricing (tier prices)',
        'stock_sources' => 'Stock per source (MSI)',
        EntityRegistry::CATEGORY => 'Categories',
        EntityRegistry::ATTRIBUTE => 'Product attributes and attribute sets',
        'customer' => 'Customers',
        'customer_composite' => 'Customers with addresses',
        'customer_address' => 'Customer addresses',
        EntityRegistry::ORDER => 'Orders',
        EntityRegistry::SHIPMENT => 'Shipments and tracking numbers',
        EntityRegistry::URL_REWRITE => 'URL rewrites',
        EntityRegistry::CMS_PAGE => 'CMS pages',
        EntityRegistry::CMS_BLOCK => 'CMS blocks',
    ];

    /**
     * One line under "What" for the chosen entity.
     */
    private const HINTS = [
        'catalog_product' => 'Products with attributes, prices, stock, categories and images. New products also need attribute set, type and website ("Add required fields" in step 4).',
        EntityRegistry::STOCK_PRICE => '"Stock & prices" changes only quantity, stock status and prices of products that already exist.',
        'advanced_pricing' => 'Tier and customer group prices of existing products.',
        'stock_sources' => 'Quantity and status per inventory source (MSI).',
        EntityRegistry::CATEGORY => 'Categories by path, e.g. "Default Category/Men/Shoes"; missing parents are created on the way.',
        EntityRegistry::ATTRIBUTE => 'Product attributes with options and store labels, assigned to attribute sets. Delete only takes them out of the set.',
        'customer' => 'Customer accounts by e-mail and website.',
        'customer_composite' => 'Customer accounts with their billing and shipping address in one row.',
        'customer_address' => 'Addresses of existing customers.',
        EntityRegistry::ORDER => 'Orders, one row per order or per item; optionally only orders not exported before.',
        EntityRegistry::ORDER . ':import' => 'Historic orders as they are, one row per item (the layout of the order export): items, addresses, payment recorded, state and status, totals from the file; optionally invoices, shipments and credit memos. Orders are only added.',
        EntityRegistry::SHIPMENT => 'Ships orders by order number: all open items or one SKU with a quantity, carrier and tracking number, optional customer e-mail. Orders that already carry the tracking number are skipped.',
        EntityRegistry::CMS_PAGE => 'CMS pages by URL key (identifier) and store views.',
        EntityRegistry::CMS_BLOCK => 'CMS blocks by identifier and store views.',
        EntityRegistry::URL_REWRITE => 'URL rewrites by request path and store view: custom targets with 301, 302 or a plain rewrite, or the URL of a product (SKU), category (path) or CMS page. Magento\'s own generated URLs are never changed.',
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
        $import[EntityRegistry::ATTRIBUTE] = ['label' => self::NAMES[EntityRegistry::ATTRIBUTE]];
        $import[EntityRegistry::CMS_PAGE] = ['label' => self::NAMES[EntityRegistry::CMS_PAGE]];
        $import[EntityRegistry::CMS_BLOCK] = ['label' => self::NAMES[EntityRegistry::CMS_BLOCK]];
        $import[EntityRegistry::URL_REWRITE] = ['label' => self::NAMES[EntityRegistry::URL_REWRITE]];
        $import[EntityRegistry::ORDER] = ['label' => self::NAMES[EntityRegistry::ORDER]];
        $import[EntityRegistry::SHIPMENT] = ['label' => self::NAMES[EntityRegistry::SHIPMENT]];
        $export = $this->exportConfig->getEntities();
        $export[EntityRegistry::ORDER] = ['label' => self::NAMES[EntityRegistry::ORDER]];
        $export[EntityRegistry::CATEGORY] = ['label' => self::NAMES[EntityRegistry::CATEGORY]];
        $export[EntityRegistry::ATTRIBUTE] = ['label' => self::NAMES[EntityRegistry::ATTRIBUTE]];
        $export[EntityRegistry::CMS_PAGE] = ['label' => self::NAMES[EntityRegistry::CMS_PAGE]];
        $export[EntityRegistry::CMS_BLOCK] = ['label' => self::NAMES[EntityRegistry::CMS_BLOCK]];
        $export[EntityRegistry::URL_REWRITE] = ['label' => self::NAMES[EntityRegistry::URL_REWRITE]];
        $order = array_flip(array_keys(self::NAMES));
        foreach (['import' => $import, 'export' => $export] as $direction => $entities) {
            uksort($entities, static fn ($a, $b): int => [$order[$a] ?? PHP_INT_MAX, (string) $a]
                <=> [$order[$b] ?? PHP_INT_MAX, (string) $b]);
            foreach ($entities as $code => $entity) {
                $label = self::NAMES[$code] ?? (string) ($entity['label'] ?? $code);
                $hint = self::HINTS[$code . ':' . $direction] ?? self::HINTS[$code] ?? '';
                $options[] = [
                    'value' => (string) $code,
                    'label' => __($label),
                    'direction' => $direction,
                    'hint' => $hint === '' ? '' : (string) __($hint),
                ];
            }
        }

        return $options;
    }
}
