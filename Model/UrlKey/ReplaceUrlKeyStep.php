<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\UrlKey;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Pipeline\Step\AbstractRowStep;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

/**
 * "Replace existing records" for products: Magento deletes each product and creates it again, and builds a URL key
 * from the name only for SKUs it does not know. Existing products of a file without url_key would all get an empty
 * key and collide ("Url key: '' was already generated …"). This step gives them the URL key they have now (a value
 * from the file or from a URL key pattern is kept).
 */
class ReplaceUrlKeyStep extends AbstractRowStep
{
    private const COLUMN = 'url_key';

    private const CHUNK = 500;

    private bool $active = false;

    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function begin(ProfileInterface $profile, string $behavior, bool $dryRun): bool
    {
        $this->active = $profile->getEntity() === 'catalog_product' && $behavior === ProfileInterface::BEHAVIOR_REPLACE;

        return $this->active;
    }

    /**
     * @inheritDoc
     */
    public function columns(array $columns): array
    {
        if ($this->active && !in_array(self::COLUMN, $columns, true)) {
            $columns[] = self::COLUMN;
        }

        return $columns;
    }

    /**
     * @inheritDoc
     */
    public function apply(array $records, \Closure $tick): void
    {
        if (!$this->active) {
            return;
        }
        $skus = [];
        foreach ($records as $record) {
            if (!$record->isFailed() && $record->identifier !== '') {
                $skus[] = $record->identifier;
            }
        }
        $keys = $this->keys($skus);
        foreach ($records as $record) {
            $key = $keys[mb_strtolower($record->identifier)] ?? '';
            if ($key === '') {
                continue;
            }
            foreach ($record->rows as $index => $row) {
                // The default row only: store view rows keep what the file says.
                if (trim((string) ($row['store_view_code'] ?? '')) === '' && trim((string) ($row[self::COLUMN] ?? '')) === '') {
                    $record->rows[$index][self::COLUMN] = $key;
                }
            }
        }
    }

    /**
     * @param string[] $skus
     * @return array<string, string> lower-case SKU => current URL key (default scope)
     */
    private function keys(array $skus): array
    {
        $keys = [];
        foreach (array_chunk(array_values(array_unique($skus)), self::CHUNK) as $chunk) {
            $collection = $this->collectionFactory->create();
            $collection->setStoreId(0);
            $collection->addAttributeToSelect(self::COLUMN);
            $collection->addAttributeToFilter('sku', ['in' => $chunk]);
            foreach ($collection as $product) {
                $keys[mb_strtolower((string) $product->getSku())] = (string) $product->getData(self::COLUMN);
            }
        }

        return $keys;
    }
}
