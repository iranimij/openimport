<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Import\UrlRewrite;

use Iranimij\OpenImport\Model\Bridge\ImportRequest;
use Iranimij\OpenImport\Model\Bridge\ImportResult;
use Iranimij\OpenImport\Model\Bridge\RowError;
use Iranimij\OpenImport\Model\Import\UrlRewrite\UrlRewriteImporter;
use Magento\ImportExport\Model\Import;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The URL rewrite importer against an in-memory shop: one store view "default" (1) and "de" (2), product SHIRT (10)
 * with the generated URL shirt.html, category Men (20), CMS page about (30), a custom rewrite /sale.
 */
class UrlRewriteImporterTest extends TestCase
{
    private FakeRecords $records;

    protected function setUp(): void
    {
        $this->records = new FakeRecords();
    }

    /**
     * @param array<int, array<string, string>> $rows
     */
    private function import(array $rows, string $behavior = Import::BEHAVIOR_APPEND, bool $dryRun = false): ImportResult
    {
        $importer = new UrlRewriteImporter($this->records, new NullLogger());
        $request = new ImportRequest('openimport_url_rewrite', $behavior, $dryRun);

        return $importer->run($request, new ArraySource($rows));
    }

    /**
     * @param RowError[] $errors
     */
    private function messages(array $errors): string
    {
        return implode("\n", array_map(static fn (RowError $error): string => $error->message, $errors));
    }

    public function testCreatesAndUpdatesRewritesOfEveryKind(): void
    {
        $result = $this->import([
            ['request_path' => '/old-shirt.html', 'product_sku' => 'shirt', 'redirect_type' => 'permanent'],
            ['request_path' => 'men-old', 'store_code' => 'DE', 'category_path' => 'Default Category/Men', 'redirect_type' => '302'],
            ['request_path' => 'info', 'cms_page' => 'about', 'description' => 'Alias of the CMS page'],
            ['request_path' => 'sale', 'target_path' => 'catalog/category/view/id/20', 'redirect_type' => ''],
            ['request_path' => 'promo', 'target_path' => 'https://example.com/x', 'redirect_type' => '301', 'entity_type' => 'custom', 'metadata' => '{"a":1}'],
        ]);

        self::assertTrue($result->valid, $this->messages($result->errors));
        self::assertSame([4, 1, 0], [$result->created, $result->updated, $result->deleted]);
        $saved = $this->records->saved;
        self::assertSame(['old-shirt.html', 'shirt.html', 301, 'product', 10, 1], $this->pick($saved[0]));
        self::assertSame(['men-old', 'catalog/category/view/id/20', 302, 'category', 20, 2], $this->pick($saved[1]), 'no generated URL: canonical path');
        self::assertSame(['info', 'cms/page/view/page_id/30', 0, 'cms-page', 30, 1], $this->pick($saved[2]));
        self::assertSame(['sale', 'catalog/category/view/id/20', 0, 'custom', 0, 1], $this->pick($saved[3]));
        self::assertSame(5, $saved[3]['existing_id'], 'the custom rewrite /sale exists and is updated');
        self::assertSame('{"a":1}', $saved[4]['metadata']);
        self::assertSame('Alias of the CMS page', $saved[2]['description']);
        self::assertNull($saved[0]['description']);
    }

    public function testDryRunCountsWithoutWriting(): void
    {
        $result = $this->import([
            ['request_path' => 'new', 'target_path' => 'x'],
            ['request_path' => 'sale', 'target_path' => 'y'],
        ], Import::BEHAVIOR_APPEND, true);

        self::assertTrue($result->valid);
        self::assertFalse($result->imported);
        self::assertSame([1, 1], [$result->created, $result->updated]);
        self::assertSame([], $this->records->saved);
    }

    public function testReportsConflictsAndBadRows(): void
    {
        $result = $this->import([
            ['request_path' => 'shirt.html', 'target_path' => 'elsewhere', 'redirect_type' => '301'],
            ['request_path' => 'shirt.html', 'product_sku' => 'shirt', 'description' => 'same entity: allowed'],
            ['request_path' => 'a b', 'target_path' => 'x'],
            ['request_path' => 'loop', 'target_path' => '/loop'],
            ['request_path' => 'two', 'product_sku' => 'shirt', 'cms_page' => 'about'],
            ['request_path' => 'mismatch', 'entity_type' => 'category', 'product_sku' => 'shirt'],
            ['request_path' => 'needs', 'entity_type' => 'product'],
            ['request_path' => 'kind', 'entity_type' => 'brand', 'target_path' => 'x'],
            ['request_path' => 'nocat', 'category_path' => 'Nope'],
            ['request_path' => 'nopage', 'cms_page' => 'nope'],
            ['request_path' => 'json', 'target_path' => 'x', 'metadata' => '{'],
            ['request_path' => '', 'target_path' => 'x'],
        ]);

        self::assertFalse($result->valid);
        $messages = $this->messages($result->errors);
        self::assertStringContainsString('"shirt.html" is the URL Magento generated for product SHIRT; it cannot be changed.', $messages);
        self::assertStringContainsString('"a b" is not a path', $messages);
        self::assertStringContainsString('"loop" points to itself.', $messages);
        self::assertStringContainsString('Name one of product_sku, category_path and cms_page, not several.', $messages);
        self::assertStringContainsString('The entity type is category but product_sku is filled.', $messages);
        self::assertStringContainsString('A product rewrite needs the product_sku column.', $messages);
        self::assertStringContainsString('"brand" is not an entity type', $messages);
        self::assertStringContainsString('There is no category "Nope".', $messages);
        self::assertStringContainsString('There is no cms page "nope".', $messages);
        self::assertStringContainsString('The metadata is not JSON.', $messages);
        self::assertStringContainsString('The request path is empty.', $messages);
        self::assertSame(11, $result->invalidRows);
        foreach ($result->errors as $error) {
            self::assertNotNull($error->row, 'every error names its row');
        }
        self::assertSame(1, $result->updated, 'the same entity may change its generated URL');
    }

    public function testDeleteAndReplace(): void
    {
        $result = $this->import([
            ['request_path' => 'sale'],
            ['request_path' => 'missing'],
            ['request_path' => 'shirt.html'],
        ], Import::BEHAVIOR_DELETE);
        self::assertFalse($result->valid);
        $messages = $this->messages($result->errors);
        self::assertStringContainsString('There is no rewrite for "missing"', $messages);
        self::assertStringContainsString('"shirt.html" is the URL Magento generated for product SHIRT; it cannot be deleted', $messages);

        $deleted = $this->import([['request_path' => 'sale']], Import::BEHAVIOR_DELETE);
        self::assertTrue($deleted->valid);
        self::assertSame(1, $deleted->deleted);
        self::assertSame([5], $this->records->deleted);

        $replace = $this->import([['request_path' => 'x', 'target_path' => 'y']], Import::BEHAVIOR_REPLACE);
        self::assertFalse($replace->valid);
        self::assertStringContainsString('cannot be imported with "Replace"', $this->messages($replace->errors));
    }

    /**
     * @param array<string, mixed> $saved
     * @return array<int, mixed>
     */
    private function pick(array $saved): array
    {
        return [$saved['request_path'], $saved['target_path'], $saved['redirect_type'], $saved['entity_type'],
            $saved['entity_id'], $saved['store_id']];
    }
}
