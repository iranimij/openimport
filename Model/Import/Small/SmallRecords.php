<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Small;

use Iranimij\OpenImport\Model\Import\Category\CategoryTree;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

/**
 * What the small entities name their records by: store views and websites by code, products by SKU, customers by
 * e-mail, categories by path.
 */
class SmallRecords
{
    /**
     * @var array<string, int>|null
     */
    private ?array $products = null;

    /**
     * @var array<int, string>|null
     */
    private ?array $skus = null;

    /**
     * @param ResourceConnection $resource
     * @param StoreManagerInterface $storeManager
     * @param CategoryTree $categories
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly CategoryTree $categories
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
     * @param string $code "" = the default store view
     * @return int|null
     */
    public function storeId(string $code): ?int
    {
        if ($code === '') {
            return (int) $this->storeManager->getDefaultStoreView()?->getId();
        }

        return $this->storeCodes()[strtolower($code)] ?? null;
    }

    /**
     * @param int $id
     * @return string the code, "all" for 0
     */
    public function storeCode(int $id): string
    {
        if ($id === 0) {
            return 'all';
        }
        foreach ($this->storeManager->getStores() as $store) {
            if ((int) $store->getId() === $id) {
                return (string) $store->getCode();
            }
        }

        return (string) $id;
    }

    /**
     * @param int $storeId
     * @return int the website of a store view
     */
    public function websiteOf(int $storeId): int
    {
        try {
            return (int) $this->storeManager->getStore($storeId)->getWebsiteId();
        } catch (\Exception $exception) {
            return 0;
        }
    }

    /**
     * @return array<string, int> lower-cased website code => id
     */
    public function websites(): array
    {
        $websites = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $websites[strtolower((string) $website->getCode())] = (int) $website->getId();
        }

        return $websites;
    }

    /**
     * @param int $id
     * @return string
     */
    public function websiteCode(int $id): string
    {
        foreach ($this->storeManager->getWebsites() as $website) {
            if ((int) $website->getId() === $id) {
                return (string) $website->getCode();
            }
        }

        return (string) $id;
    }

    /**
     * @param string $sku
     * @return int|null
     */
    public function productId(string $sku): ?int
    {
        $this->loadProducts();

        return $this->products[mb_strtolower(trim($sku))] ?? null;
    }

    /**
     * @param int $id
     * @return string|null
     */
    public function sku(int $id): ?string
    {
        $this->loadProducts();

        return $this->skus[$id] ?? null;
    }

    /**
     * @return void
     */
    private function loadProducts(): void
    {
        if ($this->products !== null) {
            return;
        }
        $this->products = [];
        $this->skus = [];
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchPairs(
            $connection->select()->from($this->resource->getTableName('catalog_product_entity'), ['entity_id', 'sku'])
        );
        foreach ($rows as $id => $sku) {
            $this->products[mb_strtolower((string) $sku)] = (int) $id;
            $this->skus[(int) $id] = (string) $sku;
        }
    }

    /**
     * @param string $email
     * @param int $websiteId
     * @return int|null the customer with that e-mail on the website
     */
    public function customerId(string $email, int $websiteId): ?int
    {
        $connection = $this->resource->getConnection();
        $id = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('customer_entity'), ['entity_id'])
                ->where('email = ?', $email)
                ->where('website_id = ?', $websiteId)
        );

        return $id === false || $id === null || $id === '' ? null : (int) $id;
    }

    /**
     * @param int $id
     * @return string|null
     */
    public function customerEmail(int $id): ?string
    {
        $connection = $this->resource->getConnection();
        $email = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('customer_entity'), ['email'])
                ->where('entity_id = ?', $id)
        );

        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * @param string $path
     * @return int|null
     */
    public function categoryId(string $path): ?int
    {
        return $this->categories->id($path);
    }

    /**
     * @param int $id
     * @return string|null
     */
    public function categoryPath(int $id): ?string
    {
        return $this->categories->path($id);
    }

    /**
     * @return ResourceConnection
     */
    public function resource(): ResourceConnection
    {
        return $this->resource;
    }
}
