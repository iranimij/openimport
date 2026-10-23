<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Order;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\Store\Model\StoreManagerInterface;

/**
 * What the order import looks up in the shop: order numbers, products by SKU, customers by e-mail, customer
 * groups, stores and order statuses. Read once per run through plain selects (thousands of rows per file).
 */
class OrderRecords
{
    /**
     * @var array<string, array<string, string>>|null status => [state, label]
     */
    private ?array $statuses = null;

    /**
     * @var array<string, true>|null
     */
    private ?array $countries = null;

    /**
     * @var array<string, int>|null code => id
     */
    private ?array $groups = null;

    /**
     * @param ResourceConnection $resource
     * @param StoreManagerInterface $storeManager
     * @param OrderConfig $orderConfig
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly OrderConfig $orderConfig
    ) {
    }

    /**
     * @param string[] $incrementIds
     * @return array<string, true> order numbers that exist
     */
    public function existingOrders(array $incrementIds): array
    {
        if ($incrementIds === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $found = $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('sales_order'), ['increment_id'])
                ->where('increment_id IN (?)', array_values(array_unique($incrementIds)))
        );

        return array_fill_keys(array_map('strval', $found), true);
    }

    /**
     * @param string[] $skus
     * @return array<string, array{id: int, type: string, weight: float|null}> lower-cased SKU => product
     */
    public function products(array $skus): array
    {
        if ($skus === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('catalog_product_entity'), ['entity_id', 'sku', 'type_id'])
                ->where('sku IN (?)', array_values(array_unique($skus)))
        );
        $products = [];
        foreach ($rows as $row) {
            $products[mb_strtolower((string) $row['sku'])] = [
                'id' => (int) $row['entity_id'],
                'type' => (string) $row['type_id'],
                'weight' => null,
            ];
        }

        return $products;
    }

    /**
     * @param string[] $emails
     * @param int $websiteId
     * @return array<string, array{id: int, group_id: int, firstname: string, lastname: string}> lower-cased e-mail
     */
    public function customers(array $emails, int $websiteId): array
    {
        if ($emails === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->resource->getTableName('customer_entity'),
                    ['entity_id', 'email', 'group_id', 'firstname', 'lastname', 'website_id']
                )
                ->where('email IN (?)', array_values(array_unique($emails)))
                ->where('website_id = ?', $websiteId)
        );
        $customers = [];
        foreach ($rows as $row) {
            $customers[mb_strtolower((string) $row['email'])] = [
                'id' => (int) $row['entity_id'],
                'group_id' => (int) $row['group_id'],
                'firstname' => (string) $row['firstname'],
                'lastname' => (string) $row['lastname'],
            ];
        }

        return $customers;
    }

    /**
     * @return array<string, array<string, string>> status code => [state, label]
     */
    public function statuses(): array
    {
        if ($this->statuses === null) {
            $this->statuses = [];
            $connection = $this->resource->getConnection();
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from(['s' => $this->resource->getTableName('sales_order_status')], ['status', 'label'])
                    ->joinLeft(
                        ['st' => $this->resource->getTableName('sales_order_status_state')],
                        'st.status = s.status',
                        ['state']
                    )
            );
            foreach ($rows as $row) {
                $status = (string) $row['status'];
                // A status may sit in several states; keep the first, the default status of a state wins below.
                if (!isset($this->statuses[$status]) || $this->statuses[$status]['state'] === '') {
                    $this->statuses[$status] = ['state' => (string) ($row['state'] ?? ''), 'label' => (string) $row['label']];
                }
            }
        }

        return $this->statuses;
    }

    /**
     * @param string $state
     * @return string the default status of a state ('' when unknown)
     */
    public function defaultStatus(string $state): string
    {
        return (string) ($this->orderConfig->getStateDefaultStatus($state) ?? '');
    }

    /**
     * @return array<string, int> customer group code (lower-cased) => id
     */
    public function groups(): array
    {
        if ($this->groups === null) {
            $this->groups = [];
            $connection = $this->resource->getConnection();
            $rows = $connection->fetchPairs(
                $connection->select()
                    ->from($this->resource->getTableName('customer_group'), ['customer_group_code', 'customer_group_id'])
            );
            foreach ($rows as $code => $id) {
                $this->groups[mb_strtolower((string) $code)] = (int) $id;
            }
        }

        return $this->groups;
    }

    /**
     * @param string $code store code, '' = the default store
     * @return array{id: int, website_id: int, name: string, currency: string}|null
     */
    public function store(string $code): ?array
    {
        try {
            $store = $code === '' ? $this->storeManager->getDefaultStoreView() : $this->storeManager->getStore($code);
        } catch (\Exception $exception) {
            return null;
        }
        if (!$store instanceof \Magento\Store\Model\Store) {
            return null;
        }

        return [
            'id' => (int) $store->getId(),
            'website_id' => (int) $store->getWebsiteId(),
            'name' => (string) $store->getName(),
            'currency' => (string) $store->getBaseCurrencyCode(),
        ];
    }

    /**
     * @return array<string, true> the country codes Magento knows (directory_country), upper case
     */
    public function countries(): array
    {
        if ($this->countries === null) {
            $connection = $this->resource->getConnection();
            $codes = $connection->fetchCol(
                $connection->select()->from($this->resource->getTableName('directory_country'), ['country_id'])
            );
            $this->countries = array_fill_keys(array_map(static fn ($code): string => strtoupper((string) $code), $codes), true);
        }

        return $this->countries;
    }
}
