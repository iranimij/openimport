<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Rest;

use Iranimij\OpenImport\Model\Rest\RestPaging;
use PHPUnit\Framework\TestCase;

class RestPagingTest extends TestCase
{
    public function testValuesAndRecords(): void
    {
        $paging = new RestPaging();
        $answer = ['data' => ['items' => [['sku' => 'A'], ['sku' => 'B']]], 'meta' => ['next' => 'c2', 'count' => 2]];

        self::assertSame('c2', $paging->value($answer, 'meta.next'));
        self::assertSame('A', $paging->value($answer, 'data.items.0.sku'));
        self::assertNull($paging->value($answer, 'meta.missing'));
        self::assertSame($answer, $paging->value($answer, ''));
        self::assertSame([['sku' => 'A'], ['sku' => 'B']], $paging->records($answer, 'data.items'));
        self::assertSame([['id' => 1]], $paging->records([['id' => 1]], ''), 'a list as the answer');
        self::assertSame([['id' => 1]], $paging->records(['total' => 1, 'products' => [['id' => 1]]], ''), 'the first list');
        self::assertNull($paging->records(['total' => 1], ''));
        self::assertNull($paging->records($answer, 'meta'), 'an object is no list');
        self::assertSame([], $paging->records(['data' => []], 'data'));
    }

    public function testLinksAndQueries(): void
    {
        $paging = new RestPaging();

        self::assertSame(
            'https://api.example.com/products?page=3',
            $paging->nextLink('<https://api.example.com/products?page=1>; rel="prev", <https://api.example.com/products?page=3>; rel="next"')
        );
        self::assertSame('/next', $paging->nextLink('</next>; type="x"; rel=next'));
        self::assertSame('', $paging->nextLink('<https://api.example.com/products?page=1>; rel="last"'));
        self::assertSame('/products?page=2&status=active', $paging->withQuery('/products?status=active&page=1', ['page' => '2']));
        self::assertSame('/products?cursor=a%2Bb', $paging->withQuery('/products', ['cursor' => 'a+b']));
    }
}
