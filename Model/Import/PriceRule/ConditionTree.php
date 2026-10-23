<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\PriceRule;

use Magento\Rule\Model\Condition\AbstractCondition;

/**
 * The condition trees of price rules as the file carries them: Magento's own condition arrays (type, attribute,
 * operator, value, aggregator, conditions) as JSON, with short type names allowed (`Product`, `Address`,
 * `Product\Found`, `Combine`), and the simple columns of common cases (SKUs, categories, a subtotal) that build a
 * tree without JSON.
 */
class ConditionTree
{
    public const CART = 'cart';
    public const CATALOG = 'catalog';

    private const NAMESPACE = [
        self::CART => 'Magento\\SalesRule\\Model\\Rule\\Condition\\',
        self::CATALOG => 'Magento\\CatalogRule\\Model\\Rule\\Condition\\',
    ];

    private const KEEP = ['type', 'attribute', 'operator', 'value', 'aggregator'];

    /**
     * The tree of a JSON column, with short types expanded and every type checked.
     *
     * @param string $json
     * @param string $kind cart or catalog
     * @param string $root the class the root must be (short name)
     * @return array<string, mixed>|string the tree, or the problem
     */
    public function parse(string $json, string $kind, string $root): array|string
    {
        $tree = json_decode($json, true);
        if (!is_array($tree)) {
            return 'not JSON';
        }
        $problem = $this->expand($tree, $kind);
        if ($problem !== null) {
            return $problem;
        }
        $expected = self::NAMESPACE[$kind] . $root;
        if (($tree['type'] ?? '') !== $expected) {
            return sprintf('the root must be of type %s (short: %s)', $expected, $root);
        }

        return $tree;
    }

    /**
     * A tree from the simple columns: an empty root, with the children given.
     *
     * @param string $kind
     * @param string $root short root type
     * @param array<int, array<string, mixed>> $children
     * @return array<string, mixed>
     */
    public function build(string $kind, string $root, array $children): array
    {
        $tree = [
            'type' => self::NAMESPACE[$kind] . $root,
            'attribute' => null,
            'operator' => null,
            'value' => '1',
            'aggregator' => 'all',
        ];
        if ($children !== []) {
            $tree['conditions'] = $children;
        }

        return $tree;
    }

    /**
     * A product condition (SKU or category is one of), for a catalog rule directly, for a cart rule wrapped in
     * "an item is found in the cart".
     *
     * @param string $kind
     * @param string $attribute sku or category_ids
     * @param string $value comma list
     * @param bool $found wrap in Product\Found (cart conditions) rather than a bare product condition (cart actions)
     * @return array<string, mixed>
     */
    public function productIn(string $kind, string $attribute, string $value, bool $found): array
    {
        $condition = [
            'type' => self::NAMESPACE[$kind] . 'Product',
            'attribute' => $attribute,
            'operator' => '()',
            'value' => $value,
        ];
        if (!$found) {
            return $condition;
        }

        return [
            'type' => self::NAMESPACE[$kind] . 'Product\\Found',
            'attribute' => null,
            'operator' => null,
            'value' => '1',
            'aggregator' => 'all',
            'conditions' => [$condition],
        ];
    }

    /**
     * A cart condition on the subtotal.
     *
     * @param string $operator
     * @param string $value
     * @return array<string, mixed>
     */
    public function subtotal(string $operator, string $value): array
    {
        return [
            'type' => self::NAMESPACE[self::CART] . 'Address',
            'attribute' => 'base_subtotal',
            'operator' => $operator,
            'value' => $value,
        ];
    }

    /**
     * A saved tree as the export writes it: the known keys only, empty ones dropped, no `is_value_processed`.
     *
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    public function compact(array $tree): array
    {
        $out = [];
        foreach (self::KEEP as $key) {
            if (isset($tree[$key]) && $tree[$key] !== '' && $tree[$key] !== []) {
                $out[$key] = $tree[$key];
            }
        }
        if (isset($tree['conditions']) && is_array($tree['conditions'])) {
            $out['conditions'] = array_map(fn (array $child): array => $this->compact($child), $tree['conditions']);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $tree
     * @return string JSON, "" for a root without children
     */
    public function toJson(array $tree): string
    {
        $compact = $this->compact($tree);
        if (!isset($compact['conditions'])) {
            return '';
        }

        return (string) json_encode($compact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Expands short types in place and checks every node.
     *
     * @param array<string, mixed> $node
     * @param string $kind
     * @return string|null the problem
     */
    private function expand(array &$node, string $kind): ?string
    {
        $type = trim((string) ($node['type'] ?? ''));
        if ($type === '') {
            return 'a condition has no type';
        }
        $type = str_replace('/', '\\', $type);
        if (!str_contains($type, '\\') || !class_exists($type)) {
            $full = self::NAMESPACE[$kind] . ltrim($type, '\\');
            if (class_exists($full)) {
                $type = $full;
            }
        }
        if (!class_exists($type) || !is_subclass_of($type, AbstractCondition::class)) {
            return sprintf('"%s" is not a condition type', $type);
        }
        $node['type'] = $type;
        if (isset($node['conditions'])) {
            if (!is_array($node['conditions'])) {
                return 'conditions must be a list';
            }
            foreach ($node['conditions'] as &$child) {
                if (!is_array($child)) {
                    return 'conditions must be a list of conditions';
                }
                $problem = $this->expand($child, $kind);
                if ($problem !== null) {
                    return $problem;
                }
            }
            unset($child);
        }

        return null;
    }
}
