<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Category;

use Iranimij\OpenImport\Model\Entity\RecordReaderInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * What the shop's categories look like now, by path, for the dry-run preview and the safety threshold.
 */
class CategoryRecordReader implements RecordReaderInterface
{
    private const BATCH = 500;

    /**
     * @param CategoryTree $tree
     * @param CollectionFactory $collectionFactory
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly CategoryTree $tree,
        private readonly CollectionFactory $collectionFactory,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function existing(array $identifiers): array
    {
        $existing = [];
        foreach ($identifiers as $identifier) {
            if ($this->tree->id((string) $identifier) !== null) {
                $existing[mb_strtolower((string) $identifier)] = true;
            }
        }

        return $existing;
    }

    /**
     * @inheritDoc
     */
    public function current(array $identifiers, array $columns, ?string $storeCode = null): array
    {
        $attributes = array_values(array_intersect($columns, CategoryImporter::COLUMNS));
        $attributes = array_values(array_diff($attributes, ['path', 'store_view_code']));
        $byId = [];
        foreach ($identifiers as $identifier) {
            $id = $this->tree->id((string) $identifier);
            if ($id !== null) {
                $byId[$id] = mb_strtolower((string) $identifier);
            }
        }
        if ($byId === [] || $attributes === []) {
            return [];
        }
        $storeId = Store::DEFAULT_STORE_ID;
        if ($storeCode !== null && $storeCode !== '') {
            foreach ($this->storeManager->getStores() as $store) {
                if ($store->getCode() === $storeCode) {
                    $storeId = (int) $store->getId();
                }
            }
        }
        $current = [];
        foreach (array_chunk(array_keys($byId), self::BATCH) as $ids) {
            $collection = $this->collectionFactory->create();
            $collection->setStoreId($storeId)
                ->addAttributeToSelect($attributes)
                ->addFieldToFilter('entity_id', ['in' => $ids]);
            foreach ($collection as $category) {
                $values = [];
                foreach ($attributes as $attribute) {
                    $value = $category->getData($attribute);
                    $values[$attribute] = $value === null ? '' : (string) $value;
                }
                $current[$byId[(int) $category->getId()]] = $values;
            }
        }

        return $current;
    }

    /**
     * @inheritDoc
     */
    public function total(): int
    {
        return count($this->tree->all());
    }
}
