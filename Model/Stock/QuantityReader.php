<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Stock;

use Magento\Framework\App\ResourceConnection;

/**
 * Current quantities: of the default stock (cataloginventory_stock_item, what the qty column of Magento's product
 * import writes) and per inventory source (MSI). Reads only; writes go through Magento's import.
 */
class QuantityReader
{
    private const CHUNK = 500;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @param string[] $skus
     * @return array<string, float> lower-cased SKU => quantity; products without a stock item are missing
     */
    public function products(array $skus): array
    {
        $connection = $this->resource->getConnection();
        $quantities = [];
        foreach (array_chunk(array_values(array_unique($skus)), self::CHUNK) as $chunk) {
            $rows = $connection->fetchPairs(
                $connection->select()
                    ->from(['e' => $this->resource->getTableName('catalog_product_entity')], ['sku'])
                    ->join(
                        ['s' => $this->resource->getTableName('cataloginventory_stock_item')],
                        's.product_id = e.entity_id AND s.stock_id = 1',
                        ['qty']
                    )
                    ->where('e.sku IN (?)', $chunk)
            );
            foreach ($rows as $sku => $qty) {
                $quantities[mb_strtolower((string) $sku)] = (float) $qty;
            }
        }

        return $quantities;
    }

    /**
     * @param array<int, array{0: string, 1: string}> $pairs SKU and source code
     * @return array<string, float> "lower-cased SKU|source code" => quantity
     */
    public function sources(array $pairs): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('inventory_source_item');
        if ($pairs === [] || !$connection->isTableExists($table)) {
            return [];
        }
        $quantities = [];
        foreach (array_chunk(array_values(array_unique(array_column($pairs, 0))), self::CHUNK) as $chunk) {
            foreach ($connection->fetchAll(
                $connection->select()->from($table, ['sku', 'source_code', 'quantity'])->where('sku IN (?)', $chunk)
            ) as $row) {
                $quantities[mb_strtolower((string) $row['sku']) . '|' . $row['source_code']] = (float) $row['quantity'];
            }
        }

        return $quantities;
    }
}
