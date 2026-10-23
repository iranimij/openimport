<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Small;

use Iranimij\OpenImport\Api\Data\ProfileInterface;

/**
 * Product positions in categories as file rows (catalog_category_product with category paths and SKUs).
 */
class CategoryPositionSource implements SmallSourceInterface
{
    /**
     * @param SmallRecords $records
     */
    public function __construct(private readonly SmallRecords $records)
    {
    }

    /**
     * @inheritDoc
     */
    public function read(ProfileInterface $profile, callable $onHeader, callable $onRow): void
    {
        $onHeader(CategoryPositionImporter::COLUMNS);
        $connection = $this->records->resource()->getConnection();
        $statement = $connection->query(
            $connection->select()
                ->from($this->records->resource()->getTableName('catalog_category_product'))
                ->order(['category_id ASC', 'position ASC', 'product_id ASC'])
        );
        while (($row = $statement->fetch()) !== false) {
            $path = $this->records->categoryPath((int) $row['category_id']);
            $sku = $this->records->sku((int) $row['product_id']);
            if ($path === null || $sku === null) {
                continue;
            }
            $onRow(['category_path' => $path, 'product_sku' => $sku, 'position' => (string) (int) $row['position']]);
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
        return CategoryPositionImporter::COLUMNS;
    }

    /**
     * @inheritDoc
     */
    public function find(string $identifier): ?array
    {
        [$path, $sku] = array_pad(explode('|', $identifier, 2), 2, '');
        $categoryId = $this->records->categoryId(trim($path));
        $productId = $this->records->productId($sku);
        if ($categoryId === null || $productId === null) {
            return null;
        }
        $position = $this->position($categoryId, $productId);

        return $position === null
            ? null
            : ['category_path' => trim($path), 'product_sku' => trim($sku), 'position' => (string) $position];
    }

    /**
     * @inheritDoc
     */
    public function count(): int
    {
        $connection = $this->records->resource()->getConnection();

        return (int) $connection->fetchOne(
            $connection->select()
                ->from($this->records->resource()->getTableName('catalog_category_product'), ['COUNT(*)'])
        );
    }

    /**
     * @param int $categoryId
     * @param int $productId
     * @return int|null the position, null when the product is not in the category
     */
    public function position(int $categoryId, int $productId): ?int
    {
        $connection = $this->records->resource()->getConnection();
        $position = $connection->fetchOne(
            $connection->select()
                ->from($this->records->resource()->getTableName('catalog_category_product'), ['position'])
                ->where('category_id = ?', $categoryId)
                ->where('product_id = ?', $productId)
        );

        return $position === false || $position === null ? null : (int) $position;
    }
}
