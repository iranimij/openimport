<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Configurable;

/**
 * Builds the core rows of a configurable product from one of its variants (the template) and the variant list:
 * product type, name, variations; the variant's own values (price, attribute set, websites, categories,
 * description, pictures …) stay, so the parent looks like its variants.
 */
class ParentBuilder
{
    public const COLUMN_VARIATIONS = 'configurable_variations';
    public const VISIBLE = 'Catalog, Search';

    /**
     * @param string $sku the parent's SKU
     * @param array<int, array<string, string>> $template a variant's core rows (default row first, then store views)
     * @param array<string, array<string, string>> $variants variant SKU => attribute code => value (label)
     * @param string[] $attributes variation attribute codes, in order
     * @param array<string, string> $names store view code ('' = default) => parent name
     * @param string[] $clear columns emptied on the parent (pictures of a template that did not pass the steps)
     * @param bool $own the template is the parent's own row from the file: its name, URL key, visibility and stock
     *                  stay as mapped
     * @return array<int, array<string, string>>
     */
    public function build(
        string $sku,
        array $template,
        array $variants,
        array $attributes,
        array $names,
        array $clear = [],
        bool $own = false
    ): array {
        $rows = [];
        foreach ($template as $index => $row) {
            $store = (string) ($row['store_view_code'] ?? '');
            $row['sku'] = $sku;
            foreach ($attributes as $attribute) {
                $row[$attribute] = '';
            }
            foreach ($clear as $column) {
                if (array_key_exists($column, $row)) {
                    $row[$column] = '';
                }
            }
            if (array_key_exists(self::COLUMN_VARIATIONS, $row)) {
                $row[self::COLUMN_VARIATIONS] = '';
            }
            if (!$own) {
                if (array_key_exists('url_key', $row)) {
                    $row['url_key'] = '';
                }
                if (array_key_exists('visibility', $row)) {
                    $row['visibility'] = $index === 0 ? self::VISIBLE : '';
                }
                if (isset($names[$store]) && $names[$store] !== '') {
                    $row['name'] = $names[$store];
                }
            }
            if ($index === 0) {
                $row['product_type'] = 'configurable';
                $row[self::COLUMN_VARIATIONS] = $this->variations($variants, $attributes);
                if (!$own && array_key_exists('qty', $row)) {
                    $row['qty'] = '';
                }
                if (!$own && array_key_exists('is_in_stock', $row)) {
                    $row['is_in_stock'] = '1';
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Core's configurable_variations: "sku=A-1,color=Red,size=M|sku=A-2,color=Blue,size=M".
     *
     * @param array<string, array<string, string>> $variants
     * @param string[] $attributes
     * @return string
     */
    public function variations(array $variants, array $attributes): string
    {
        $parts = [];
        foreach ($variants as $sku => $values) {
            $pairs = ['sku=' . $sku];
            foreach ($attributes as $attribute) {
                $pairs[] = $attribute . '=' . ($values[$attribute] ?? '');
            }
            $parts[] = implode(',', $pairs);
        }

        return implode('|', $parts);
    }

    /**
     * The parent's name from its variants' names: the words they all start with ("Shirt Red M", "Shirt Blue L" →
     * "Shirt"), without a trailing separator; the first name when they share no word or there is only one.
     *
     * @param string[] $names
     * @return string
     */
    public function commonName(array $names): string
    {
        $names = array_values(array_filter(array_map('trim', $names), static fn (string $name): bool => $name !== ''));
        if ($names === []) {
            return '';
        }
        if (count($names) === 1) {
            return $names[0];
        }
        $common = preg_split('/\s+/u', $names[0]) ?: [];
        foreach (array_slice($names, 1) as $name) {
            $words = preg_split('/\s+/u', $name) ?: [];
            $length = 0;
            while ($length < count($common) && $length < count($words)
                && mb_strtolower($common[$length]) === mb_strtolower($words[$length])) {
                $length++;
            }
            $common = array_slice($common, 0, $length);
        }
        $result = trim((string) preg_replace('/[\s\-–,\/|(:;]+$/u', '', implode(' ', $common)));

        return $result === '' ? $names[0] : $result;
    }
}
