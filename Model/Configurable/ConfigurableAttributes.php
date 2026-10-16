<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Configurable;

use Magento\Framework\App\ResourceConnection;

/**
 * Product attributes that can make variants of a configurable product: Magento accepts dropdowns with global scope
 * that the shop defined (color, size, …).
 */
class ConfigurableAttributes
{
    /**
     * @var array<string, array{label: string, global: bool, input: string, user: bool}>|null
     */
    private ?array $attributes = null;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @return array<string, string> code => label of the attributes that can make variants
     */
    public function eligible(): array
    {
        $eligible = [];
        foreach ($this->all() as $code => $attribute) {
            if ($this->problem($code) === null) {
                $eligible[$code] = $attribute['label'];
            }
        }
        asort($eligible, SORT_NATURAL | SORT_FLAG_CASE);

        return $eligible;
    }

    /**
     * @param string $code
     * @return string|null why the attribute cannot make variants, null when it can
     */
    public function problem(string $code): ?string
    {
        $attribute = $this->all()[$code] ?? null;
        if ($attribute === null) {
            return sprintf('There is no product attribute "%s".', $code);
        }
        if ($attribute['input'] !== 'select' || !$attribute['user']) {
            return sprintf('"%s" is not a dropdown the shop defined; variants need one.', $code);
        }
        if (!$attribute['global']) {
            return sprintf('"%s" has no global scope; Magento needs global scope for variants.', $code);
        }

        return null;
    }

    /**
     * @return array<string, array{label: string, global: bool, input: string, user: bool}>
     */
    private function all(): array
    {
        if ($this->attributes === null) {
            $connection = $this->resource->getConnection();
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from(
                        ['a' => $this->resource->getTableName('eav_attribute')],
                        ['attribute_code', 'frontend_label', 'frontend_input', 'is_user_defined']
                    )
                    ->join(
                        ['c' => $this->resource->getTableName('catalog_eav_attribute')],
                        'c.attribute_id = a.attribute_id',
                        ['is_global']
                    )
                    ->join(
                        ['t' => $this->resource->getTableName('eav_entity_type')],
                        't.entity_type_id = a.entity_type_id',
                        []
                    )
                    ->where('t.entity_type_code = ?', 'catalog_product')
            );
            $this->attributes = [];
            foreach ($rows as $row) {
                $code = (string) $row['attribute_code'];
                $this->attributes[$code] = [
                    'label' => (string) ($row['frontend_label'] ?: $code),
                    'global' => (int) $row['is_global'] === 1,
                    'input' => (string) $row['frontend_input'],
                    'user' => (int) $row['is_user_defined'] === 1,
                ];
            }
        }

        return $this->attributes;
    }
}
