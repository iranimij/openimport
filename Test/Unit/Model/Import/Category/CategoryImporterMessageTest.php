<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Import\Category;

use Iranimij\OpenImport\Model\Import\Category\CategoryImporter;
use Iranimij\OpenImport\Model\Import\Category\CategoryPath;
use Iranimij\OpenImport\Model\Import\Category\CategoryTree;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CategoryImporterMessageTest extends TestCase
{
    private function importer(): CategoryImporter
    {
        return new CategoryImporter(
            $this->createMock(CategoryTree::class),
            new CategoryPath(),
            $this->createMock(CategoryRepositoryInterface::class),
            $this->createMock(CategoryFactory::class),
            $this->createMock(CategoryResource::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testPlainExceptionKeepsItsMessage(): void
    {
        self::assertSame('Broken.', $this->importer()->message(new \RuntimeException('Broken.')));
    }

    public function testUrlClashNamesTheTakenUrls(): void
    {
        $clash = new class ('URL key for specified store already exists.') extends \RuntimeException {
            /**
             * @return array<int, array<string, mixed>>
             */
            public function getUrls(): array
            {
                return [
                    7 => ['request_path' => 'men/shoes.html', 'store_id' => 1],
                    8 => ['request_path' => 'men/shoes.html', 'store_id' => 1],
                    9 => ['request_path' => 'men/shoes.html', 'store_id' => 2],
                ];
            }
        };
        $message = $this->importer()->message($clash);
        self::assertStringStartsWith('URL key for specified store already exists: "men/shoes.html" in store view 1, "men/shoes.html" in store view 2.', $message);
        self::assertStringContainsString('url_key', $message);
    }
}
