<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\UrlKey;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

/**
 * Which products already have a URL key (default values), read through the product collection.
 */
class UrlKeyOwners
{
    private const CHUNK = 500;

    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @param string[] $keys
     * @return array<string, string> URL key => SKU of the product that has it
     */
    public function owners(array $keys): array
    {
        $owners = [];
        foreach (array_chunk(array_values(array_unique($keys)), self::CHUNK) as $chunk) {
            $collection = $this->collectionFactory->create();
            $collection->setStoreId(0);
            $collection->addAttributeToSelect('url_key');
            $collection->addAttributeToFilter('url_key', ['in' => $chunk]);
            foreach ($collection as $product) {
                $owners[(string) $product->getData('url_key')] = (string) $product->getSku();
            }
        }

        return $owners;
    }
}
