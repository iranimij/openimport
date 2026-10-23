<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Entity;

use Magento\Eav\Model\Config as EavConfig;
use Magento\ImportExport\Model\Import\SampleFileProvider;

/**
 * The Magento fields a profile can map for an entity: the columns of core's sample file for that entity plus the
 * shop's own attributes (custom product and customer attributes), identifier and required fields first.
 */
class FieldList
{
    /**
     * Fields a new record needs (core refuses the row without them).
     */
    private const REQUIRED_FOR_NEW = [
        'catalog_product' => ['sku', 'attribute_set_code', 'product_type', 'name', 'price', 'product_websites'],
        'customer' => ['email', '_website', 'firstname', 'lastname', 'group_id'],
        'customer_composite' => ['email', '_website', 'firstname', 'lastname', 'group_id'],
        'customer_address' => ['_email', '_website', 'firstname', 'lastname', 'street', 'city', 'country_id',
            'postcode', 'telephone'],
        'stock_sources' => ['sku', 'source_code', 'status', 'quantity'],
        'openimport_category' => ['path'],
        'openimport_attribute' => ['attribute_code', 'label', 'frontend_input'],
        'openimport_cms_page' => ['identifier', 'title'],
        'openimport_cms_block' => ['identifier', 'title'],
        'openimport_shipment' => ['increment_id'],
        'openimport_url_rewrite' => ['request_path'],
        'openimport_cart_rule' => ['name', 'action', 'discount_amount'],
        'openimport_catalog_rule' => ['name', 'action', 'discount_amount'],
        'openimport_review' => ['product_sku', 'nickname', 'title', 'detail'],
        'openimport_newsletter' => ['email'],
        'openimport_search_term' => ['query_text'],
        'openimport_search_synonym' => ['synonyms'],
        'openimport_category_position' => ['category_path', 'product_sku'],
        'openimport_order' => ['increment_id', 'customer_email', 'status', 'billing_firstname', 'billing_lastname',
            'billing_street', 'billing_city', 'billing_country_id', 'item_sku', 'item_qty_ordered', 'item_price'],
    ];

    /**
     * Import columns core's sample files leave out (2.4.9's product sample has no picture columns).
     */
    private const EXTRA_COLUMNS = [
        'catalog_product' => ['base_image', 'base_image_label', 'small_image', 'small_image_label', 'thumbnail_image',
            'thumbnail_image_label', 'swatch_image', 'swatch_image_label', 'additional_images',
            'additional_image_labels', 'hide_from_product_page', 'url_key'],
    ];

    /**
     * EAV entity whose attributes are offered too.
     */
    private const EAV = ['catalog_product' => 'catalog_product', 'customer' => 'customer',
        'customer_composite' => 'customer', 'customer_address' => 'customer_address'];

    /**
     * @param SampleFileProvider $samples
     * @param EavConfig $eavConfig
     * @param EntityRegistry $entities
     */
    public function __construct(
        private readonly SampleFileProvider $samples,
        private readonly EavConfig $eavConfig,
        private readonly EntityRegistry $entities
    ) {
    }

    /**
     * @param string $entity
     * @return array<int, array{code: string, label: string, required: bool, identifier: bool}>
     */
    public function fields(string $entity): array
    {
        $info = $this->entities->get($entity);
        $codes = $info !== null && $info->columns !== [] ? $info->columns : $this->sampleColumns($entity);
        foreach (self::EXTRA_COLUMNS[$entity] ?? [] as $code) {
            if (!in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }
        $labels = [];
        if ($info === null || $info->columns === []) {
            foreach ($this->attributes($entity) as $code => $label) {
                if (!in_array($code, $codes, true)) {
                    $codes[] = $code;
                }
                $labels[$code] = $label;
            }
        }
        $identifier = $info === null ? [] : $info->identifier;
        $required = array_merge($identifier, self::REQUIRED_FOR_NEW[$entity] ?? []);
        $fields = [];
        foreach (array_values(array_unique($codes)) as $code) {
            $fields[] = [
                'code' => $code,
                'label' => $labels[$code] ?? $code,
                'required' => in_array($code, $required, true),
                'identifier' => in_array($code, $identifier, true),
            ];
        }
        usort($fields, static fn (array $a, array $b): int => [!$a['identifier'], !$a['required'], $a['code']]
            <=> [!$b['identifier'], !$b['required'], $b['code']]);

        return array_merge($fields, $this->stepFields($entity));
    }

    /**
     * Fields OpenImport's row steps read; they are not sent to Magento.
     *
     * @param string $entity
     * @return array<int, array{code: string, label: string, required: bool, identifier: bool}>
     */
    private function stepFields(string $entity): array
    {
        if ($entity !== 'catalog_product') {
            return [];
        }

        return [
            ['code' => 'configurable:parent_sku', 'label' => (string) __('Parent SKU (configurable products)'),
                'required' => false, 'identifier' => false],
            ['code' => 'configurable:parent_name', 'label' => (string) __('Parent name (configurable products)'),
                'required' => false, 'identifier' => false],
        ];
    }

    /**
     * @param string $entity
     * @return string[]
     */
    private function sampleColumns(string $entity): array
    {
        try {
            $contents = $this->samples->getFileContents($entity);
        } catch (\Exception $exception) {
            return [];
        }
        $line = strtok($contents, "\n");

        return $line === false ? [] : array_values(array_filter(
            array_map('trim', str_getcsv($line, ',', '"', '')),
            static fn (string $column): bool => $column !== ''
        ));
    }

    /**
     * @param string $entity
     * @return array<string, string> attribute code => label
     */
    private function attributes(string $entity): array
    {
        $type = self::EAV[$entity] ?? null;
        if ($type === null) {
            return [];
        }
        $attributes = [];
        foreach ($this->eavConfig->getEntityAttributes($type) as $attribute) {
            // System attributes are in core's sample file under their import names (product_online, tax_class_name
            // …); only the shop's own attributes are added.
            if (!$attribute->getIsUserDefined() || $attribute->getBackendType() === 'static') {
                continue;
            }
            $code = (string) $attribute->getAttributeCode();
            $attributes[$code] = (string) ($attribute->getDefaultFrontendLabel() ?: $code);
        }

        return $attributes;
    }
}
