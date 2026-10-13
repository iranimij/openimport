<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Entity\Reader;

use Iranimij\OpenImport\Model\Entity\RecordReaderInterface;
use Iranimij\OpenImport\Model\Value\NumberText;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Current product values in import-file terms: attributes (option labels for select attributes), product type,
 * attribute set name, product_online, visibility label, websites, qty and is_in_stock. Categories, images and
 * relations are not compared.
 */
class ProductRecordReader implements RecordReaderInterface
{
    private const BATCH = 500;
    private const VISIBILITY = [1 => 'Not Visible Individually', 2 => 'Catalog', 3 => 'Search', 4 => 'Catalog, Search'];
    private const SKIPPED = ['sku', 'store_view_code', 'categories', 'base_image', 'small_image', 'thumbnail_image',
        'swatch_image', 'additional_images', 'base_image_label', 'small_image_label', 'thumbnail_image_label',
        'swatch_image_label', 'additional_image_labels', 'additional_attributes', 'configurable_variations',
        'configurable_variation_labels', 'related_skus', 'crosssell_skus', 'upsell_skus', 'bundle_values',
        'associated_skus', 'custom_options', 'hide_from_product_page', 'product_websites'];

    /**
     * @var array<int|string, string>|null attribute set id => name
     */
    private ?array $attributeSets = null;

    private NumberText $numbers;

    /**
     * @param ResourceConnection $resource
     * @param CollectionFactory $collectionFactory
     * @param EavConfig $eavConfig
     * @param StoreManagerInterface $storeManager
     * @param ValueFormatter $formatter
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CollectionFactory $collectionFactory,
        private readonly EavConfig $eavConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly ValueFormatter $formatter
    ) {
        $this->numbers = new NumberText();
    }

    /**
     * @inheritDoc
     */
    public function existing(array $identifiers): array
    {
        $connection = $this->resource->getConnection();
        $existing = [];
        foreach (array_chunk(array_values(array_unique($identifiers)), self::BATCH) as $chunk) {
            $skus = $connection->fetchCol(
                $connection->select()
                    ->from($this->resource->getTableName('catalog_product_entity'), ['sku'])
                    ->where('sku IN (?)', $chunk)
            );
            foreach ($skus as $sku) {
                $existing[mb_strtolower((string) $sku)] = true;
            }
        }

        return $existing;
    }

    /**
     * @inheritDoc
     */
    public function current(array $identifiers, array $columns, ?string $storeCode = null): array
    {
        $storeId = $storeCode === null ? 0 : (int) $this->storeManager->getStore($storeCode)->getId();
        $attributes = [];
        foreach ($columns as $column) {
            $code = match ($column) {
                'product_online' => 'status',
                'special_price_from_date' => 'special_from_date',
                'special_price_to_date' => 'special_to_date',
                'product_type', 'attribute_set_code', 'qty', 'is_in_stock' => null,
                default => in_array($column, self::SKIPPED, true) ? null : $column,
            };
            if ($code === null) {
                continue;
            }
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
            if ($attribute && $attribute->getId() && $attribute->getBackendType() !== 'static') {
                $attributes[$column] = $attribute;
            }
        }
        $values = [];
        foreach (array_chunk(array_values(array_unique($identifiers)), self::BATCH) as $chunk) {
            $collection = $this->collectionFactory->create();
            $collection->setStoreId($storeId);
            $collection->addAttributeToFilter('sku', ['in' => $chunk]);
            foreach ($attributes as $attribute) {
                $collection->addAttributeToSelect($attribute->getAttributeCode());
            }
            $productIds = [];
            foreach ($collection as $product) {
                $key = mb_strtolower((string) $product->getSku());
                $productIds[(int) $product->getId()] = $key;
                $row = [];
                foreach ($attributes as $column => $attribute) {
                    $raw = $product->getData($attribute->getAttributeCode());
                    $row[$column] = match ($column) {
                        'product_online' => $raw === null ? '' : ((int) $raw === 1 ? '1' : '0'),
                        'visibility' => self::VISIBILITY[(int) $raw] ?? (string) $raw,
                        default => $this->formatter->format($attribute, $raw),
                    };
                }
                if (in_array('product_type', $columns, true)) {
                    $row['product_type'] = (string) $product->getTypeId();
                }
                if (in_array('attribute_set_code', $columns, true)) {
                    $row['attribute_set_code'] = $this->attributeSetName((int) $product->getAttributeSetId());
                }
                $values[$key] = $row;
            }
            if ($storeCode === null && $productIds !== []
                && (in_array('qty', $columns, true) || in_array('is_in_stock', $columns, true))) {
                foreach ($this->stock(array_keys($productIds)) as $productId => $stock) {
                    $key = $productIds[$productId];
                    if (in_array('qty', $columns, true)) {
                        $values[$key]['qty'] = $stock['qty'];
                    }
                    if (in_array('is_in_stock', $columns, true)) {
                        $values[$key]['is_in_stock'] = $stock['is_in_stock'];
                    }
                }
            }
        }

        return $values;
    }

    /**
     * @inheritDoc
     */
    public function total(): int
    {
        $connection = $this->resource->getConnection();

        return (int) $connection->fetchOne(
            $connection->select()->from($this->resource->getTableName('catalog_product_entity'), ['COUNT(*)'])
        );
    }

    /**
     * @param int[] $productIds
     * @return array<int, array{qty: string, is_in_stock: string}>
     */
    private function stock(array $productIds): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('cataloginventory_stock_item'), ['product_id', 'qty', 'is_in_stock'])
                ->where('product_id IN (?)', $productIds)
                ->where('stock_id = ?', 1)
        );
        $stock = [];
        foreach ($rows as $row) {
            $stock[(int) $row['product_id']] = [
                'qty' => $row['qty'] === null ? '' : $this->numbers->fromFloat((float) $row['qty']),
                'is_in_stock' => (string) (int) $row['is_in_stock'],
            ];
        }

        return $stock;
    }

    private function attributeSetName(int $setId): string
    {
        if ($this->attributeSets === null) {
            $connection = $this->resource->getConnection();
            $this->attributeSets = array_map('strval', $connection->fetchPairs(
                $connection->select()->from(
                    $this->resource->getTableName('eav_attribute_set'),
                    ['attribute_set_id', 'attribute_set_name']
                )
            ));
        }

        return $this->attributeSets[$setId] ?? '';
    }
}
