<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Attribute;

use Magento\Eav\Api\Data\AttributeOptionInterface;
use Magento\Eav\Api\Data\AttributeOptionInterfaceFactory;
use Magento\Eav\Api\Data\AttributeOptionLabelInterfaceFactory;
use Magento\Framework\App\ResourceConnection;

/**
 * Options of a select attribute with their admin labels and every store view's label, read straight from the
 * option tables (reading only). Saving goes through the attribute repository, which replaces all labels of an option:
 * so every option handed to it carries all its store labels, and nothing is lost.
 */
class AttributeOptions
{
    /**
     * @param ResourceConnection $resource
     * @param AttributeOptionInterfaceFactory $optionFactory
     * @param AttributeOptionLabelInterfaceFactory $labelFactory
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly AttributeOptionInterfaceFactory $optionFactory,
        private readonly AttributeOptionLabelInterfaceFactory $labelFactory
    ) {
    }

    /**
     * @param int $attributeId
     * @return array<int, array{label: string, sort: int, stores: array<int, string>}> option id => data, in sort order
     */
    public function load(int $attributeId): array
    {
        if ($attributeId <= 0) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['o' => $this->resource->getTableName('eav_attribute_option')], ['option_id', 'sort_order'])
                ->joinLeft(
                    ['v' => $this->resource->getTableName('eav_attribute_option_value')],
                    'v.option_id = o.option_id',
                    ['store_id', 'value']
                )
                ->where('o.attribute_id = ?', $attributeId)
                ->order(['o.sort_order ASC', 'o.option_id ASC'])
        );
        $options = [];
        foreach ($rows as $row) {
            $id = (int) $row['option_id'];
            $options[$id] ??= ['label' => '', 'sort' => (int) $row['sort_order'], 'stores' => []];
            if ($row['store_id'] === null) {
                continue;
            }
            if ((int) $row['store_id'] === 0) {
                $options[$id]['label'] = (string) $row['value'];
            } else {
                $options[$id]['stores'][(int) $row['store_id']] = (string) $row['value'];
            }
        }

        return $options;
    }

    /**
     * The attribute's own store-view labels (not the options').
     *
     * @param int $attributeId
     * @return array<int, string> store id => label
     */
    public function attributeLabels(int $attributeId): array
    {
        if ($attributeId <= 0) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $pairs = $connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName('eav_attribute_label'), ['store_id', 'value'])
                ->where('attribute_id = ?', $attributeId)
        );
        $labels = [];
        foreach ($pairs as $storeId => $value) {
            $labels[(int) $storeId] = (string) $value;
        }

        return $labels;
    }

    /**
     * @param int $attributeId
     * @return string[] admin labels in sort order
     */
    public function labels(int $attributeId): array
    {
        return array_values(array_map(static fn (array $option): string => $option['label'], $this->load($attributeId)));
    }

    /**
     * @param int $attributeId
     * @param int $storeId
     * @return string[] the store's labels in the order of the options ('' where the store has none)
     */
    public function storeLabels(int $attributeId, int $storeId): array
    {
        return array_values(array_map(
            static fn (array $option): string => $option['stores'][$storeId] ?? '',
            $this->load($attributeId)
        ));
    }

    /**
     * Option objects for the repository: existing options with all their labels, then new ones.
     *
     * @param array<int, array{label: string, sort: int, stores: array<int, string>}> $existing
     * @param string[] $newLabels admin labels of options to add
     * @return AttributeOptionInterface[]
     */
    public function build(array $existing, array $newLabels = []): array
    {
        $options = [];
        $sort = 0;
        foreach ($existing as $id => $data) {
            $option = $this->optionFactory->create();
            $option->setValue((string) $id);
            $option->setLabel($data['label']);
            $option->setSortOrder($data['sort']);
            $labels = [];
            foreach ($data['stores'] as $storeId => $label) {
                if ($label !== '') {
                    $labels[] = $this->labelFactory->create()->setStoreId($storeId)->setLabel($label);
                }
            }
            $option->setStoreLabels($labels);
            $options[] = $option;
            $sort = max($sort, $data['sort']);
        }
        foreach ($newLabels as $label) {
            $option = $this->optionFactory->create();
            $option->setLabel($label);
            $option->setSortOrder(++$sort);
            $option->setStoreLabels([]);
            $options[] = $option;
        }

        return $options;
    }
}
