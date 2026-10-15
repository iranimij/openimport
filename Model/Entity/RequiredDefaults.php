<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Entity;

use Magento\Eav\Api\AttributeSetRepositoryInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Fixed values "Add required fields" proposes for fields a file usually does not have: the shop's default attribute
 * set and website, a simple product, the default customer group, the default inventory source.
 */
class RequiredDefaults
{
    /**
     * @param StoreManagerInterface $storeManager
     * @param EavConfig $eavConfig
     * @param AttributeSetRepositoryInterface $attributeSets
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly EavConfig $eavConfig,
        private readonly AttributeSetRepositoryInterface $attributeSets,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @param string $entity
     * @return array<string, string> field code => proposed fixed value
     */
    public function forEntity(string $entity): array
    {
        return match ($entity) {
            'catalog_product' => array_filter([
                'attribute_set_code' => $this->defaultAttributeSet(),
                'product_type' => 'simple',
                'product_websites' => $this->defaultWebsite(),
            ]),
            'customer', 'customer_composite' => array_filter([
                '_website' => $this->defaultWebsite(),
                'group_id' => (string) $this->scopeConfig->getValue('customer/create_account/default_group'),
            ]),
            'customer_address' => array_filter(['_website' => $this->defaultWebsite()]),
            'stock_sources' => ['source_code' => 'default', 'status' => '1'],
            default => [],
        };
    }

    /**
     * @return string
     */
    private function defaultWebsite(): string
    {
        try {
            $store = $this->storeManager->getDefaultStoreView();

            return $store === null ? '' : (string) $this->storeManager->getWebsite((int) $store->getWebsiteId())->getCode();
        } catch (\Exception $exception) {
            return '';
        }
    }

    /**
     * @return string
     */
    private function defaultAttributeSet(): string
    {
        try {
            $id = (int) $this->eavConfig->getEntityType('catalog_product')->getDefaultAttributeSetId();

            return (string) $this->attributeSets->get($id)->getAttributeSetName();
        } catch (\Exception $exception) {
            return '';
        }
    }
}
