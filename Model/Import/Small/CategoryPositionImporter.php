<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Small;

use Magento\Catalog\Api\CategoryLinkRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryProductLinkInterfaceFactory;
use Magento\ImportExport\Model\Import;
use Psr\Log\LoggerInterface;

/**
 * Product positions in categories: a product (SKU) in a category (path) at a position. Add/Update assigns the
 * product and sets the position, Delete removes it from the category. Written through Magento's category link
 * repository, as the admin's "Products in Category" grid does.
 */
class CategoryPositionImporter extends SmallImporter
{
    public const COLUMNS = ['category_path', 'product_sku', 'position'];

    /**
     * @param LoggerInterface $logger
     * @param SmallRecords $records
     * @param CategoryPositionSource $source
     * @param CategoryProductLinkInterfaceFactory $linkFactory
     * @param CategoryLinkRepositoryInterface $links
     */
    public function __construct(
        LoggerInterface $logger,
        private readonly SmallRecords $records,
        private readonly CategoryPositionSource $source,
        private readonly CategoryProductLinkInterfaceFactory $linkFactory,
        private readonly CategoryLinkRepositoryInterface $links
    ) {
        parent::__construct($logger);
    }

    /**
     * @inheritDoc
     */
    protected function what(): string
    {
        return 'Category positions';
    }

    /**
     * @inheritDoc
     */
    protected function prepare(array $row, string $behavior): array
    {
        $path = $row['category_path'] ?? '';
        $categoryId = $path === '' ? null : $this->records->categoryId($path);
        if ($categoryId === null) {
            return ['error' => ['category_path',
                $path === '' ? 'the category is empty' : sprintf('there is no category "%s"', $path)]];
        }
        $sku = $row['product_sku'] ?? '';
        $productId = $sku === '' ? null : $this->records->productId($sku);
        if ($productId === null) {
            return ['error' => ['product_sku',
                $sku === '' ? 'the product SKU is empty' : sprintf('there is no product "%s"', $sku)]];
        }
        $existing = $this->source->position($categoryId, $productId);
        $key = $categoryId . '|' . $productId;
        if ($behavior === Import::BEHAVIOR_DELETE) {
            if ($existing === null) {
                return ['error' => ['product_sku', 'is not in this category']];
            }

            return ['key' => $key, 'exists' => true, 'category_id' => $categoryId,
                'sku' => (string) $this->records->sku($productId)];
        }
        $position = $row['position'] ?? '';
        if ($position !== '' && preg_match('/^-?\d+$/', $position) !== 1) {
            return ['error' => ['position', sprintf('"%s" is not a whole number', $position)]];
        }

        return [
            'key' => $key,
            'exists' => $existing !== null,
            'category_id' => $categoryId,
            'sku' => (string) $this->records->sku($productId),
            'position' => $position !== '' ? (int) $position : ($existing ?? 0),
        ];
    }

    /**
     * @inheritDoc
     */
    protected function write(array $prepared): void
    {
        $link = $this->linkFactory->create();
        $link->setCategoryId($prepared['category_id'])->setSku($prepared['sku'])->setPosition($prepared['position']);
        $this->links->save($link);
    }

    /**
     * @inheritDoc
     */
    protected function remove(array $prepared): void
    {
        $this->links->deleteByIds($prepared['category_id'], $prepared['sku']);
    }
}
