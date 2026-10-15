<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Category;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Export\EntitySourceInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Category export: one row per category with its default values (path by names, deepest last), plus one row per
 * store view that overrides any of the text values. The file imports back into the same shop or another one.
 */
class CategoryEntitySource implements EntitySourceInterface
{
    private const BATCH = 500;

    private const EXPORTED = ['name', 'url_key', 'is_active', 'include_in_menu', 'is_anchor', 'position',
        'description', 'meta_title', 'meta_keywords', 'meta_description', 'display_mode', 'custom_design',
        'page_layout'];

    private const STORE_SCOPED = ['name', 'url_key', 'description', 'meta_title', 'meta_keywords',
        'meta_description', 'display_mode', 'custom_design', 'page_layout'];

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
    public function read(ProfileInterface $profile, callable $onHeader, callable $onRow): void
    {
        $this->tree->reset();
        $onHeader($this->columns($profile->getEntity()));
        $options = $profile->getExportOptions();
        $withStores = empty($options['category_rows']) || $options['category_rows'] !== 'default';
        $stores = [];
        if ($withStores) {
            foreach ($this->storeManager->getStores() as $store) {
                $stores[(int) $store->getId()] = (string) $store->getCode();
            }
        }
        $paths = $this->tree->all();
        foreach (array_chunk(array_keys($paths), self::BATCH, true) as $ids) {
            $defaults = $this->load($ids, Store::DEFAULT_STORE_ID);
            $perStore = [];
            foreach ($stores as $storeId => $code) {
                $perStore[$code] = $this->load($ids, $storeId);
            }
            foreach ($ids as $id) {
                $default = $defaults[$id] ?? null;
                if ($default === null) {
                    continue;
                }
                $onRow(['path' => $paths[$id], 'store_view_code' => ''] + $default);
                foreach ($perStore as $code => $rows) {
                    $row = $rows[$id] ?? null;
                    if ($row === null) {
                        continue;
                    }
                    $overrides = [];
                    foreach (self::STORE_SCOPED as $attribute) {
                        if (($row[$attribute] ?? '') !== ($default[$attribute] ?? '')) {
                            $overrides[$attribute] = $row[$attribute];
                        }
                    }
                    if ($overrides !== []) {
                        $onRow(['path' => $paths[$id], 'store_view_code' => $code] + $overrides
                            + array_fill_keys(self::EXPORTED, ''));
                    }
                }
            }
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
        return array_merge(['path', 'store_view_code'], self::EXPORTED);
    }

    /**
     * @param int[] $ids
     * @param int $storeId
     * @return array<int, array<string, string>>
     */
    private function load(array $ids, int $storeId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId)
            ->addAttributeToSelect(self::EXPORTED)
            ->addFieldToFilter('entity_id', ['in' => $ids]);
        $rows = [];
        foreach ($collection as $category) {
            $row = [];
            foreach (self::EXPORTED as $attribute) {
                $value = $category->getData($attribute);
                $row[$attribute] = $value === null ? '' : (string) $value;
            }
            $rows[(int) $category->getId()] = $row;
        }

        return $rows;
    }
}
