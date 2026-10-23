<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Import\PriceRule;

use Iranimij\OpenImport\Model\Import\PriceRule\ConditionTree;
use PHPUnit\Framework\TestCase;

class ConditionTreeTest extends TestCase
{
    private const CART = 'Magento\\SalesRule\\Model\\Rule\\Condition\\';
    private const CATALOG = 'Magento\\CatalogRule\\Model\\Rule\\Condition\\';

    public function testParsesShortAndFullTypesAndChecksEveryNode(): void
    {
        $trees = new ConditionTree();
        $tree = $trees->parse(
            '{"type":"Combine","aggregator":"any","value":"1","conditions":[{"type":"Address","attribute":"base_subtotal",'
            . '"operator":">=","value":"50"},{"type":"Product\\\\Found","value":"1","aggregator":"all","conditions":'
            . '[{"type":"' . addslashes(self::CART . 'Product') . '","attribute":"sku","operator":"()","value":"A, B"}]}]}',
            ConditionTree::CART,
            'Combine'
        );
        self::assertIsArray($tree);
        self::assertSame(self::CART . 'Combine', $tree['type']);
        self::assertSame(self::CART . 'Address', $tree['conditions'][0]['type']);
        self::assertSame(self::CART . 'Product\\Found', $tree['conditions'][1]['type']);
        self::assertSame(self::CART . 'Product', $tree['conditions'][1]['conditions'][0]['type']);

        self::assertSame('not JSON', $trees->parse('{', ConditionTree::CART, 'Combine'));
        self::assertSame('a condition has no type', $trees->parse('{"aggregator":"all"}', ConditionTree::CART, 'Combine'));
        self::assertStringContainsString(
            'is not a condition type',
            (string) $trees->parse('{"type":"Nope"}', ConditionTree::CART, 'Combine')
        );
        self::assertStringContainsString(
            'the root must be of type',
            (string) $trees->parse('{"type":"Product"}', ConditionTree::CART, 'Combine')
        );
        self::assertStringContainsString(
            'is not a condition type',
            (string) $trees->parse('{"type":"Combine","conditions":[{"type":"\\\\stdClass"}]}', ConditionTree::CATALOG, 'Combine')
        );
        self::assertSame(
            'conditions must be a list of conditions',
            $trees->parse('{"type":"Combine","conditions":["x"]}', ConditionTree::CATALOG, 'Combine')
        );
    }

    public function testBuildsTreesFromTheSimpleColumns(): void
    {
        $trees = new ConditionTree();
        $cart = $trees->build(ConditionTree::CART, 'Combine', [
            $trees->subtotal('>=', '20'),
            $trees->productIn(ConditionTree::CART, 'sku', 'A, B', true),
        ]);
        self::assertSame(self::CART . 'Combine', $cart['type']);
        self::assertSame('all', $cart['aggregator']);
        self::assertSame(['base_subtotal', '>=', '20'], [
            $cart['conditions'][0]['attribute'], $cart['conditions'][0]['operator'], $cart['conditions'][0]['value'],
        ]);
        self::assertSame(self::CART . 'Product\\Found', $cart['conditions'][1]['type']);
        self::assertSame('sku', $cart['conditions'][1]['conditions'][0]['attribute']);

        $catalog = $trees->build(ConditionTree::CATALOG, 'Combine', [
            $trees->productIn(ConditionTree::CATALOG, 'category_ids', '3, 4', false),
        ]);
        self::assertSame(self::CATALOG . 'Product', $catalog['conditions'][0]['type']);
        self::assertSame('()', $catalog['conditions'][0]['operator']);
        self::assertArrayNotHasKey('conditions', $trees->build(ConditionTree::CATALOG, 'Combine', []));
    }

    public function testCompactsSavedTreesForTheExport(): void
    {
        $trees = new ConditionTree();
        $saved = [
            'type' => self::CART . 'Combine', 'attribute' => null, 'operator' => null, 'value' => '1',
            'is_value_processed' => null, 'aggregator' => 'all',
            'conditions' => [
                ['type' => self::CART . 'Address', 'attribute' => 'base_subtotal', 'operator' => '>=', 'value' => '20',
                    'is_value_processed' => false],
            ],
        ];
        self::assertSame(
            '{"type":"' . self::CART . 'Combine","value":"1","aggregator":"all","conditions":[{"type":"' . self::CART
            . 'Address","attribute":"base_subtotal","operator":">=","value":"20"}]}',
            str_replace('\\\\', '\\', $trees->toJson($saved))
        );
        self::assertSame('', $trees->toJson(['type' => self::CART . 'Combine', 'aggregator' => 'all']), 'no children: empty');
        $round = $trees->parse($trees->toJson($saved), ConditionTree::CART, 'Combine');
        self::assertIsArray($round);
        self::assertSame('20', $round['conditions'][0]['value']);
    }
}
