<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Cms;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\BlockFactory;
use Magento\Cms\Model\PageFactory;
use Magento\Cms\Model\ResourceModel\Block\CollectionFactory as BlockCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Framework\Model\AbstractModel;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Reading and writing CMS pages and blocks for the importer, the record reader and the export.
 */
class CmsRecords
{
    public const PAGE = 'page';
    public const BLOCK = 'block';
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const UNCHANGED = 'unchanged';

    /**
     * @param PageRepositoryInterface $pages
     * @param BlockRepositoryInterface $blocks
     * @param PageFactory $pageFactory
     * @param BlockFactory $blockFactory
     * @param PageCollectionFactory $pageCollectionFactory
     * @param BlockCollectionFactory $blockCollectionFactory
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly PageRepositoryInterface $pages,
        private readonly BlockRepositoryInterface $blocks,
        private readonly PageFactory $pageFactory,
        private readonly BlockFactory $blockFactory,
        private readonly PageCollectionFactory $pageCollectionFactory,
        private readonly BlockCollectionFactory $blockCollectionFactory,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return array<string, int> lower-cased store view code => id
     */
    public function storeCodes(): array
    {
        $codes = [];
        foreach ($this->storeManager->getStores() as $store) {
            $codes[strtolower((string) $store->getCode())] = (int) $store->getId();
        }

        return $codes;
    }

    /**
     * @param int $storeId
     * @return string the code, "all" for 0
     */
    public function storeCode(int $storeId): string
    {
        if ($storeId === 0) {
            return 'all';
        }
        foreach ($this->storeManager->getStores() as $store) {
            if ((int) $store->getId() === $storeId) {
                return (string) $store->getCode();
            }
        }

        return (string) $storeId;
    }

    /**
     * The record with this identifier that is assigned to one of the store ids (or to all store views); with no
     * store ids given, the first record with the identifier.
     *
     * @param string $kind
     * @param string $identifier
     * @param int[] $storeIds
     * @return AbstractModel|null
     */
    public function find(string $kind, string $identifier, array $storeIds): ?AbstractModel
    {
        $collection = $kind === self::PAGE ? $this->pageCollectionFactory->create() : $this->blockCollectionFactory->create();
        $collection->addFieldToFilter('identifier', trim($identifier));
        $fallback = null;
        foreach ($collection as $model) {
            $assigned = $this->storesOf($model);
            if ($storeIds === [] || in_array(0, $assigned, true) || array_intersect($storeIds, $assigned) !== []
                || in_array(0, $storeIds, true)) {
                return $model;
            }
            $fallback ??= $model;
        }

        return $storeIds === [] ? $fallback : null;
    }

    /**
     * @param string $kind
     * @param array<string, mixed> $row columns of the kind
     * @param int[] $storeIds [] = keep the record's stores (new records: all store views)
     * @return string CREATED, UPDATED or UNCHANGED
     */
    public function save(string $kind, array $row, array $storeIds): string
    {
        $identifier = trim((string) $row['identifier']);
        $model = $this->find($kind, $identifier, $storeIds);
        $created = $model === null;
        if ($created) {
            $model = $kind === self::PAGE ? $this->pageFactory->create() : $this->blockFactory->create();
            $model->setData('identifier', $identifier);
            $model->setData('is_active', 1);
            $model->setData('stores', $storeIds === [] ? [0] : $storeIds);
        } elseif ($storeIds !== []) {
            $current = $this->storesOf($model);
            sort($current);
            $wanted = $storeIds;
            sort($wanted);
            if ($current !== $wanted) {
                $model->setData('stores', $wanted);
            }
        }
        $changed = $created;
        foreach ($row as $column => $value) {
            if (in_array($column, ['identifier', 'stores'], true) || $value === null) {
                continue;
            }
            if ($column === 'is_active') {
                if (trim((string) $value) === '') {
                    continue;
                }
                $value = $this->flag($value) ? 1 : 0;
            } elseif ($column === 'sort_order') {
                if (trim((string) $value) === '') {
                    continue;
                }
                $value = (int) $value;
            }
            if ((string) $model->getData($column) !== (string) $value) {
                $model->setData($column, $value);
                $changed = true;
            }
        }
        if (!$changed && !$model->hasDataChanges()) {
            return self::UNCHANGED;
        }
        if ($kind === self::PAGE) {
            $this->pages->save($model);
        } else {
            $this->blocks->save($model);
        }

        return $created ? self::CREATED : self::UPDATED;
    }

    /**
     * @param string $kind
     * @param string $identifier
     * @param int[] $storeIds
     * @return bool whether a record was deleted
     */
    public function delete(string $kind, string $identifier, array $storeIds): bool
    {
        $model = $this->find($kind, $identifier, $storeIds);
        if ($model === null) {
            return false;
        }
        if ($kind === self::PAGE) {
            $this->pages->deleteById((int) $model->getId());
        } else {
            $this->blocks->deleteById((string) $model->getId());
        }

        return true;
    }

    /**
     * Every record of the kind, as file rows (stores as codes).
     *
     * @param string $kind
     * @param string[] $columns
     * @return \Generator<int, array<string, string>>
     */
    public function rows(string $kind, array $columns): \Generator
    {
        $collection = $kind === self::PAGE ? $this->pageCollectionFactory->create() : $this->blockCollectionFactory->create();
        $collection->setOrder('identifier', 'ASC');
        foreach ($collection as $model) {
            $row = [];
            foreach ($columns as $column) {
                if ($column === 'stores') {
                    $row[$column] = implode(',', array_map(
                        fn (int $id): string => $this->storeCode($id),
                        $this->storesOf($model)
                    ));
                } else {
                    $value = $model->getData($column);
                    $row[$column] = $value === null ? '' : (string) $value;
                }
            }
            yield $row;
        }
    }

    /**
     * @param string $kind
     * @return int
     */
    public function count(string $kind): int
    {
        $collection = $kind === self::PAGE ? $this->pageCollectionFactory->create() : $this->blockCollectionFactory->create();

        return $collection->getSize();
    }

    /**
     * Store ids a page or block is assigned to (0 = all store views).
     *
     * @param AbstractModel $model
     * @return int[]
     */
    public function storesOf(AbstractModel $model): array
    {
        $stores = $model->hasData('stores') ? $model->getData('stores') : $model->getData('store_id');

        return array_map('intval', (array) $stores);
    }

    /**
     * @param mixed $value
     * @return bool|null
     */
    public function flag(mixed $value): ?bool
    {
        return match (strtolower(trim((string) $value))) {
            '1', 'yes', 'true', 'ja', 'y', 'on' => true,
            '0', 'no', 'false', 'nein', 'n', 'off' => false,
            default => null,
        };
    }
}
