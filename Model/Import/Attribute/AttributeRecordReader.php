<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Attribute;

use Iranimij\OpenImport\Model\Entity\RecordReaderInterface;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * What the shop's product attributes look like now, for the dry-run preview and the safety threshold.
 */
class AttributeRecordReader implements RecordReaderInterface
{
    /**
     * @param ProductAttributeRepositoryInterface $attributes
     * @param AttributeSets $sets
     * @param AttributeOptions $options
     * @param SearchCriteriaBuilder $criteria
     */
    public function __construct(
        private readonly ProductAttributeRepositoryInterface $attributes,
        private readonly AttributeSets $sets,
        private readonly AttributeOptions $options,
        private readonly SearchCriteriaBuilder $criteria
    ) {
    }

    /**
     * @inheritDoc
     */
    public function existing(array $identifiers): array
    {
        $existing = [];
        foreach ($identifiers as $identifier) {
            if ($this->find((string) $identifier) !== null) {
                $existing[mb_strtolower((string) $identifier)] = true;
            }
        }

        return $existing;
    }

    /**
     * @inheritDoc
     */
    public function current(array $identifiers, array $columns, ?string $storeCode = null): array
    {
        if ($storeCode !== null && $storeCode !== '') {
            return []; // store labels are not previewed field by field
        }
        $current = [];
        foreach ($identifiers as $identifier) {
            $attribute = $this->find((string) $identifier);
            if ($attribute === null) {
                continue;
            }
            $values = [];
            foreach ($columns as $column) {
                $value = $this->value($attribute, $column);
                if ($value !== null) {
                    $values[$column] = $value;
                }
            }
            $current[mb_strtolower((string) $identifier)] = $values;
        }

        return $current;
    }

    /**
     * @inheritDoc
     */
    public function total(): int
    {
        $criteria = $this->criteria->addFilter('is_user_defined', 1)->create();

        return (int) $this->attributes->getList($criteria)->getTotalCount();
    }

    /**
     * @param ProductAttributeInterface $attribute
     * @param string $column
     * @return string|null null when the column is not compared
     */
    public function value(ProductAttributeInterface $attribute, string $column): ?string
    {
        switch ($column) {
            case 'label':
                return (string) $attribute->getDefaultFrontendLabel();
            case 'frontend_input':
                return (string) $attribute->getFrontendInput();
            case 'scope':
                return (string) $attribute->getScope();
            case 'default_value':
                return (string) $attribute->getDefaultValue();
            case 'is_filterable':
                return (string) (int) $attribute->getIsFilterable();
            case 'options':
                return implode(AttributeImporter::OPTION_SEPARATOR, $this->options->labels((int) $attribute->getAttributeId()));
            case 'attribute_set':
                $names = [];
                foreach ($this->sets->all() as $name => $id) {
                    if ($this->sets->has($id, (string) $attribute->getAttributeCode())) {
                        $names[] = $this->sets->name($id);
                    }
                }
                return implode(',', $names);
            default:
                if (in_array($column, AttributeImporter::COLUMNS, true)
                    && (str_starts_with($column, 'is_') || str_starts_with($column, 'used_'))) {
                    return $attribute instanceof \Magento\Framework\DataObject ? (string) (int) $attribute->getData($column) : null;
                }
                return null;
        }
    }

    /**
     * @param string $code
     * @return ProductAttributeInterface|null
     */
    private function find(string $code): ?ProductAttributeInterface
    {
        try {
            return $this->attributes->get(trim($code));
        } catch (NoSuchEntityException $exception) {
            return null;
        }
    }
}
