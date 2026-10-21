<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Import;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Entity\EntityRegistry;
use Iranimij\OpenImport\Model\Execution\RunFiles;
use Iranimij\OpenImport\Model\Execution\RunProcessor;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Iranimij\OpenImport\Model\Import\UrlRewrite\UrlRewriteImporter;
use Iranimij\OpenImport\Model\Import\UrlRewrite\UrlRewriteRecords;
use Iranimij\OpenImport\Model\Reader\ReaderFactory;
use Iranimij\OpenImport\Model\Storage\ErrorLog;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\State;
use Magento\Framework\Filesystem;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\UrlRewrite\Controller\Router;
use PHPUnit\Framework\TestCase;

/**
 * URL rewrites through OpenImport's own importer: custom and product rewrites with 301/302, the router answers,
 * conflicts with Magento's generated URLs reported, export round trip, update and delete.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class UrlRewriteImportTest extends TestCase
{
    private function file(string $content): string
    {
        $var = Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryWrite(DirectoryList::VAR_DIR);
        $relative = 'openimport/it-rewrites-' . bin2hex(random_bytes(3)) . '.csv';
        $var->writeFile($relative, $content);

        return $var->getAbsolutePath($relative);
    }

    private function profile(string $behavior = ProfileInterface::BEHAVIOR_ADD_UPDATE): ProfileInterface
    {
        $mapping = [];
        foreach (UrlRewriteImporter::COLUMNS as $column) {
            $mapping[] = ['target' => $column, 'source' => $column];
        }
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Rewrites ' . uniqid())
            ->setEntity(EntityRegistry::URL_REWRITE)
            ->setFormat('csv')
            ->setSourceType('upload')
            ->setBehavior($behavior)
            ->setSafetyThreshold(100)
            ->setMapping($mapping);

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function process(ProfileInterface $profile, string $file, string $mode = RunInterface::MODE_LIVE, array $options = []): RunInterface
    {
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue(
            $profile,
            $mode,
            RunInterface::TRIGGER_CLI,
            $options + ['file' => $file, 'original_name' => 'rewrites.csv']
        );

        return Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rewrite(string $requestPath): ?array
    {
        $records = Bootstrap::getObjectManager()->get(UrlRewriteRecords::class);

        return $records->find($requestPath, $records->defaultStoreId());
    }

    /**
     * What the storefront answers for a path: [HTTP code, Location] for a redirect, ['forward', target] for a rewrite.
     *
     * @return array{0: int|string, 1: string}
     */
    private function answer(string $path): array
    {
        $objectManager = Bootstrap::getObjectManager();
        /** @var HttpRequest $request */
        $request = $objectManager->create(HttpRequest::class);
        $request->setPathInfo('/' . $path)->setRequestUri('/' . $path);
        /** @var \Magento\Framework\App\Response\Http $response */
        $response = $objectManager->get(ResponseInterface::class);
        $response->clearHeaders();
        $action = $objectManager->get(State::class)->emulateAreaCode(
            'frontend',
            static fn () => $objectManager->create(Router::class)->match($request)
        );
        self::assertNotNull($action, 'the router knows ' . $path);
        $location = $response->getHeader('Location');
        if ($location !== false && $location !== null) {
            return [$response->getHttpResponseCode(), (string) $location->getFieldValue()];
        }

        return ['forward', ltrim((string) $request->getPathInfo(), '/')];
    }

    #[DataFixture('Magento/Catalog/_files/product_simple.php')]
    public function testRewritesRedirectsConflictsRoundTripAndDelete(): void
    {
        $tag = 'oi-' . bin2hex(random_bytes(3));
        $header = "request_path;store_code;target_path;redirect_type;entity_type;product_sku;category_path;cms_page;description;autogenerated;metadata\n";
        $file = $this->file($header
            . "{$tag}-old-shirt.html;default;;301;;simple;;;Old product URL;;\n"
            . "{$tag}-promo;;https://example.com/promo;302;custom;;;;Campaign;;\n"
            . "{$tag}-shirts;default;catalog/product/view/id/1;;;;;;Plain rewrite;;\n");
        $profile = $this->profile();

        $dry = $this->process($profile, $file, RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_SUCCESS, $dry->getStatus(), $dry->getMessage());
        self::assertSame(3, $dry->getCount('created'));
        self::assertNull($this->rewrite("{$tag}-old-shirt.html"), 'a dry run writes nothing');

        $live = $this->process($profile, $file);
        self::assertSame(RunInterface::STATUS_SUCCESS, $live->getStatus(), $live->getMessage());
        self::assertSame(3, $live->getCount('created'));
        $product = $this->rewrite("{$tag}-old-shirt.html");
        self::assertNotNull($product);
        self::assertSame('product', $product['entity_type']);
        self::assertSame(301, (int) $product['redirect_type']);
        self::assertSame('simple-product.html', $product['target_path'], 'the product\'s current URL is the target');
        self::assertSame('Old product URL', $product['description']);
        $promo = $this->rewrite("{$tag}-promo");
        self::assertSame(['custom', 302, 'https://example.com/promo'], [$promo['entity_type'], (int) $promo['redirect_type'], $promo['target_path']]);

        // The storefront answers.
        [$code, $location] = $this->answer("{$tag}-old-shirt.html");
        self::assertSame(301, $code);
        self::assertStringEndsWith('simple-product.html', $location);
        [$code, $location] = $this->answer("{$tag}-promo");
        self::assertSame(302, $code);
        self::assertSame('https://example.com/promo', $location);
        self::assertSame(['forward', 'catalog/product/view/id/1'], $this->answer("{$tag}-shirts"));

        // Update: a changed target counts as updated, the rest stays.
        $update = $this->file($header . "{$tag}-promo;;https://example.com/spring;302;custom;;;;Campaign;;\n");
        $dry2 = $this->process($profile, $update, RunInterface::MODE_DRY_RUN);
        self::assertSame([0, 1], [$dry2->getCount('created'), $dry2->getCount('updated')], $dry2->getMessage());
        $live2 = $this->process($profile, $update);
        self::assertSame(RunInterface::STATUS_SUCCESS, $live2->getStatus(), $live2->getMessage());
        self::assertSame('https://example.com/spring', $this->rewrite("{$tag}-promo")['target_path']);

        // Export round trip: the file imports back as it is.
        $export = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Rewrites export ' . uniqid())
            ->setDirection(ProfileInterface::DIRECTION_EXPORT)
            ->setEntity(EntityRegistry::URL_REWRITE)
            ->setFormat('csv')
            ->setRowFilters(['mode' => 'include', 'match' => 'all', 'conditions' => [['column' => 'request_path', 'operator' => 'starts_with', 'value' => $tag]]])
            ->setExportOptions(['destination' => 'download']);
        $export = Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($export);
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue($export, RunInterface::MODE_LIVE, RunInterface::TRIGGER_CLI);
        $run = Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $run->getMessage());
        $path = Bootstrap::getObjectManager()->get(RunFiles::class)->absolute((string) $run->getOutputFile());
        $rows = [];
        foreach (Bootstrap::getObjectManager()->get(ReaderFactory::class)->create($path, 'csv')->rows() as $row) {
            $rows[$row['request_path']] = $row;
        }
        self::assertCount(3, $rows);
        self::assertSame('simple', $rows["{$tag}-old-shirt.html"]['product_sku']);
        self::assertSame('default', $rows["{$tag}-old-shirt.html"]['store_code']);
        self::assertSame('0', $rows["{$tag}-old-shirt.html"]['autogenerated']);
        self::assertSame('302', $rows["{$tag}-promo"]['redirect_type']);
        $back = $this->process($profile, $path, RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_SUCCESS, $back->getStatus(), $back->getMessage());
        self::assertSame(
            [0, 3],
            [$back->getCount('created'), $back->getCount('updated')],
            $back->getMessage() . "\n" . implode("\n", $this->errorMessages($back))
        );

        // Conflicts and bad rows are reported, nothing is written.
        $bad = $this->file($header
            . "simple-product.html;default;{$tag}-elsewhere;301;custom;;;;;;\n"
            . "{$tag}-twice;;a;;;;;;;;\n"
            . "{$tag}-twice;;b;;;;;;;;\n"
            . "{$tag}-loop;;{$tag}-loop;301;;;;;;;\n"
            . "{$tag}-nowhere;nowhere;a;;;;;;;;\n"
            . "{$tag}-nosku;;;301;;no-such-sku;;;;;\n"
            . "{$tag}-type;;a;303;;;;;;;\n"
            . "{$tag}-empty;;;;;;;;;;\n");
        $check = $this->process($profile, $bad, RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_FAILED, $check->getStatus());
        $messages = implode("\n", $this->errorMessages($check));
        self::assertStringContainsString('"simple-product.html" is the URL Magento generated for product simple; it cannot be changed.', $messages);
        self::assertStringContainsString("\"{$tag}-twice\" appears twice in the file", $messages);
        self::assertStringContainsString("\"{$tag}-loop\" points to itself.", $messages);
        self::assertStringContainsString('There is no store view "nowhere"', $messages);
        self::assertStringContainsString('There is no product "no-such-sku".', $messages);
        self::assertStringContainsString('"303" is not a redirect type', $messages);
        self::assertStringContainsString('A custom rewrite needs a target path', $messages);
        self::assertSame('simple-product.html', $this->rewrite('simple-product.html')['request_path']);

        // Delete: the imported rewrites go, Magento's generated URL stays.
        $delete = $this->profile(ProfileInterface::BEHAVIOR_DELETE);
        $gone = $this->process($delete, $this->file($header . "{$tag}-old-shirt.html;;;;;;;;;;\n{$tag}-promo;;;;;;;;;;\n{$tag}-shirts;;;;;;;;;;\n"), RunInterface::MODE_LIVE, ['confirmed' => true]);
        self::assertSame(RunInterface::STATUS_SUCCESS, $gone->getStatus(), $gone->getMessage());
        self::assertSame(3, $gone->getCount('deleted'));
        self::assertNull($this->rewrite("{$tag}-promo"));
        $kept = $this->process($delete, $this->file($header . "simple-product.html;;;;;;;;;;\n"), RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_FAILED, $kept->getStatus());
        self::assertStringContainsString('cannot be deleted', implode("\n", $this->errorMessages($kept)));
    }

    public function testReplaceIsRefused(): void
    {
        $this->expectExceptionMessage('openimport_url_rewrite cannot be imported with "Replace": use Add/Update.');
        $this->profile(ProfileInterface::BEHAVIOR_REPLACE);
    }

    /**
     * @return string[]
     */
    private function errorMessages(RunInterface $run): array
    {
        $messages = [];
        foreach (Bootstrap::getObjectManager()->get(ErrorLog::class)->fetch((int) $run->getRunId(), 50) as $error) {
            $messages[] = $error->message;
        }

        return $messages;
    }
}
