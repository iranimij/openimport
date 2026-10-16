<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Missing;

use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The products a "complete list" covers: all, those of a website, or those with an attribute value (supplier = X).
 * Read through Magento's product collection (works with Adobe Commerce's row ids too).
 */
class MissingScope
{
    public const ALL = 'all';
    public const WEBSITE = 'website';
    public const ATTRIBUTE = 'attribute';

    /**
     * @param CollectionFactory $collectionFactory
     * @param StoreManagerInterface $storeManager
     * @param EavConfig $eavConfig
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * Checks the scope settings.
     *
     * @param array<string, string> $scope type, website, attribute, value
     * @return void
     * @throws PipelineException
     */
    public function check(array $scope): void
    {
        $this->collection($scope);
    }

    /**
     * @param array<string, string> $scope type, website, attribute, value
     * @param string $only "enabled", "in_stock" or "" (every product in the scope)
     * @return array{skus: string[], total: int} the SKUs (of the narrowed set) and how many products the scope has
     * @throws PipelineException
     */
    public function products(array $scope, string $only): array
    {
        $collection = $this->collection($scope);
        $connection = $collection->getConnection();
        $total = (int) $connection->fetchOne(
            (clone $collection->getSelect())->reset(\Magento\Framework\DB\Select::COLUMNS)->columns(['COUNT(*)'])
        );
        if ($only === 'enabled') {
            $collection->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED]);
        } elseif ($only === 'in_stock') {
            $collection->joinField(
                'openimport_in_stock',
                'cataloginventory_stock_item',
                'is_in_stock',
                'product_id=entity_id',
                '{{table}}.stock_id=1',
                'inner'
            );
            $collection->addFieldToFilter('openimport_in_stock', ['eq' => 1]);
        }
        $select = (clone $collection->getSelect())->reset(\Magento\Framework\DB\Select::COLUMNS)->columns(['e.sku']);

        return ['skus' => array_map('strval', $connection->fetchCol($select)), 'total' => $total];
    }

    /**
     * @param array<string, string> $scope
     * @return Collection
     * @throws PipelineException
     */
    private function collection(array $scope): Collection
    {
        $collection = $this->collectionFactory->create();
        $type = $scope['type'] ?? self::ALL;
        if ($type === self::WEBSITE) {
            $code = trim((string) ($scope['website'] ?? ''));
            try {
                $website = $this->storeManager->getWebsite($code);
            } catch (\Exception) {
                $website = null;
            }
            if ($code === '' || $website === null || !$website->getId()) {
                throw new PipelineException(
                    sprintf('Products missing from the file: there is no website "%s".', $code)
                );
            }
            $collection->addWebsiteFilter([(int) $website->getId()]);
        } elseif ($type === self::ATTRIBUTE) {
            $code = trim((string) ($scope['attribute'] ?? ''));
            $value = trim((string) ($scope['value'] ?? ''));
            $attribute = $code === '' ? null : $this->eavConfig->getAttribute(Product::ENTITY, $code);
            if ($attribute === null || !$attribute->getId() || $value === '') {
                throw new PipelineException(sprintf(
                    'Products missing from the file: choose a product attribute and a value (attribute "%s").',
                    $code
                ));
            }
            if (in_array($attribute->getFrontendInput(), ['select', 'multiselect', 'boolean'], true)
                && $attribute->usesSource()) {
                $option = $attribute->getSource()->getOptionId($value);
                if ($option === null || $option === '') {
                    throw new PipelineException(sprintf(
                        'Products missing from the file: "%s" is not an option of %s.',
                        $value,
                        $code
                    ));
                }
                $value = (string) $option;
            }
            $collection->addAttributeToFilter(
                $code,
                $attribute->getFrontendInput() === 'multiselect' ? ['finset' => $value] : ['eq' => $value]
            );
        } elseif ($type !== self::ALL) {
            throw new PipelineException(sprintf('Products missing from the file: unknown scope "%s".', $type));
        }

        return $collection;
    }
}
