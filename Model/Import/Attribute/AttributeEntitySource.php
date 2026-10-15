<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Attribute;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Export\EntitySourceInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Product attribute export: the shop's own attributes (export option attributes = all adds Magento's), one row per
 * attribute with options "A|B|C" and the attribute sets it belongs to, plus one row per store view that has its
 * own label or option labels. The file imports back as it is.
 */
class AttributeEntitySource implements EntitySourceInterface
{
    private const PAGE = 200;

    /**
     * @param ProductAttributeRepositoryInterface $attributes
     * @param AttributeRecordReader $reader
     * @param AttributeOptions $options
     * @param SearchCriteriaBuilder $criteria
     * @param SortOrderBuilder $sortOrder
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly ProductAttributeRepositoryInterface $attributes,
        private readonly AttributeRecordReader $reader,
        private readonly AttributeOptions $options,
        private readonly SearchCriteriaBuilder $criteria,
        private readonly SortOrderBuilder $sortOrder,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function read(ProfileInterface $profile, callable $onHeader, callable $onRow): void
    {
        $columns = $this->columns($profile->getEntity());
        $onHeader($columns);
        $options = $profile->getExportOptions();
        $all = ($options['attributes'] ?? 'own') === 'all';
        $stores = [];
        foreach ($this->storeManager->getStores() as $store) {
            $stores[(int) $store->getId()] = (string) $store->getCode();
        }
        $page = 1;
        do {
            $builder = $this->criteria->setPageSize(self::PAGE)->setCurrentPage($page)
                ->addSortOrder($this->sortOrder->setField('attribute_code')->setDirection('ASC')->create());
            if (!$all) {
                $builder->addFilter('is_user_defined', 1);
            }
            $list = $this->attributes->getList($builder->create());
            $items = $list->getItems();
            foreach ($items as $attribute) {
                $row = ['attribute_code' => (string) $attribute->getAttributeCode(), 'store_view_code' => ''];
                foreach ($columns as $column) {
                    if ($column === 'attribute_code' || $column === 'store_view_code') {
                        continue;
                    }
                    $row[$column] = $this->reader->value($attribute, $column) ?? '';
                }
                $onRow($row);
                $attributeLabels = $this->options->attributeLabels((int) $attribute->getAttributeId());
                foreach ($stores as $storeId => $code) {
                    $label = $attributeLabels[$storeId] ?? '';
                    $optionLabels = $this->options->storeLabels((int) $attribute->getAttributeId(), $storeId);
                    $any = array_filter($optionLabels, static fn (string $l): bool => $l !== '') !== [];
                    if ($label === '' && !$any) {
                        continue;
                    }
                    $onRow([
                        'attribute_code' => (string) $attribute->getAttributeCode(),
                        'store_view_code' => $code,
                        'label' => $label,
                        'options' => $any ? implode(AttributeImporter::OPTION_SEPARATOR, $optionLabels) : '',
                    ] + array_fill_keys($columns, ''));
                }
            }
            $page++;
        } while (count($items) === self::PAGE);
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
        return array_values(array_diff(AttributeImporter::COLUMNS, ['attribute_group']));
    }
}
