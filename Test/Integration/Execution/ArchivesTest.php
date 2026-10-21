<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Integration\Execution;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Archive\Unpacker;
use Iranimij\OpenImport\Model\Execution\RunFiles;
use Iranimij\OpenImport\Model\Execution\RunProcessor;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Iranimij\OpenImport\Model\Execution\SourceResolver;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Archives through the whole run: a ZIP in a server folder is unpacked and imported (the run names the file
 * inside), "read as it is" leaves it alone, an export is packed before delivery, and a bad archive fails the run
 * with a plain message.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class ArchivesTest extends TestCase
{
    private function var(): Filesystem\Directory\WriteInterface
    {
        return Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryWrite(DirectoryList::VAR_DIR);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $formatOptions
     * @return ProfileInterface
     */
    private function importProfile(array $config, array $formatOptions = []): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Archive import ' . uniqid())
            ->setEntity('catalog_product')
            ->setFormat('auto')
            ->setFormatOptions($formatOptions)
            ->setSourceType('folder')
            ->setSourceConfig($config)
            ->setMapping([
                ['target' => 'sku', 'source' => 'sku'],
                ['target' => 'name', 'source' => 'name'],
                ['target' => 'price', 'source' => 'price'],
                ['target' => 'attribute_set_code', 'source' => null, 'static' => 'Default'],
                ['target' => 'product_type', 'source' => null, 'static' => 'simple'],
                ['target' => 'product_websites', 'source' => null, 'static' => 'base'],
            ]);

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    private function process(ProfileInterface $profile, string $mode = RunInterface::MODE_LIVE): RunInterface
    {
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue($profile, $mode, RunInterface::TRIGGER_CLI);

        return Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
    }

    public function testZipInAFolderIsUnpackedAndImported(): void
    {
        $tag = bin2hex(random_bytes(3));
        $folder = 'openimport/it-zip-' . $tag;
        $var = $this->var();
        $var->create($folder);
        $zip = new \ZipArchive();
        $zip->open($var->getAbsolutePath($folder . '/feed.zip'), \ZipArchive::CREATE);
        $zip->addFromString('readme.txt', 'about this feed');
        $zip->addFromString('data/products.csv', "sku,name,price\nOI-ZIP-{$tag},Zipped {$tag},3\n");
        $zip->close();

        $profile = $this->importProfile(['path' => 'it-zip-' . $tag, 'pattern' => '*.zip']);
        $resolver = Bootstrap::getObjectManager()->get(SourceResolver::class);
        self::assertStringContainsString('feed.zip', $resolver->source($profile)->test($profile));

        $run = $this->process($profile);
        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $run->getMessage());
        self::assertSame(1, $run->getCount('created'));
        self::assertStringContainsString('Read products.csv from feed.zip.', $run->getMessage());
        self::assertSame('products.csv', $run->getOptions()['original_name'] ?? null);
        self::assertStringEndsWith('/source.csv', (string) $run->getSourceFile());
        $product = Bootstrap::getObjectManager()->get(ProductRepositoryInterface::class)->get('OI-ZIP-' . $tag);
        self::assertSame('Zipped ' . $tag, $product->getName());

        // A pattern for the file inside, and "read as it is" (a ZIP is then no CSV: the run fails plainly).
        $pattern = $this->importProfile(['path' => 'it-zip-' . $tag, 'pattern' => '*.zip'], ['archive_entry' => '*.txt']);
        $failed = $this->process($pattern, RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_FAILED, $failed->getStatus());
        self::assertStringContainsString('readme.txt', $failed->getMessage());

        $asIs = $this->importProfile(['path' => 'it-zip-' . $tag, 'pattern' => '*.zip'], ['archive' => Unpacker::MODE_NONE]);
        $plain = $this->process($asIs, RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_FAILED, $plain->getStatus());
        self::assertStringNotContainsString('from feed.zip', $plain->getMessage());
    }

    public function testGzipAndBadArchives(): void
    {
        $tag = bin2hex(random_bytes(3));
        $folder = 'openimport/it-gz-' . $tag;
        $var = $this->var();
        $var->writeFile($folder . '/stock.csv.gz', (string) gzencode("sku,name,price\nOI-GZ-{$tag},Gzipped {$tag},4\n"));
        $var->writeFile($folder . '/broken.zip', "PK\x03\x04 not really a zip");
        $var->writeFile($folder . '/empty.zip', '');

        $run = $this->process($this->importProfile(['path' => 'it-gz-' . $tag, 'pattern' => '*.gz']), RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $run->getMessage());
        self::assertStringContainsString('Read stock.csv from stock.csv.gz.', $run->getMessage());

        $broken = $this->process($this->importProfile(['path' => 'it-gz-' . $tag, 'pattern' => 'broken.zip']));
        self::assertSame(RunInterface::STATUS_FAILED, $broken->getStatus());
        self::assertSame('The ZIP file "broken.zip" cannot be opened (damaged?).', trim($broken->getMessage()));
    }

    public function testExportIsPackedBeforeDelivery(): void
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Packed export ' . uniqid())
            ->setDirection(ProfileInterface::DIRECTION_EXPORT)
            ->setEntity('catalog_product')
            ->setFormat('csv')
            ->setExportOptions(['compress' => 'zip', 'file_name' => 'all-products.{ext}']);
        $profile = Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);

        $run = $this->process($profile);
        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $run->getMessage());
        self::assertStringEndsWith('/all-products.csv.zip', (string) $run->getOutputFile());
        $file = Bootstrap::getObjectManager()->get(RunFiles::class)->absolute((string) $run->getOutputFile());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file));
        self::assertSame(1, $zip->numFiles);
        self::assertSame('all-products.csv', $zip->getNameIndex(0));
        self::assertStringStartsWith('sku,', (string) $zip->getFromIndex(0));
        $zip->close();
        self::assertFileDoesNotExist(substr($file, 0, -4), 'the plain file is replaced by the archive');

        $dry = $this->process($profile, RunInterface::MODE_DRY_RUN);
        self::assertStringEndsWith('/all-products.csv.zip', (string) $dry->getOutputFile(), 'the dry run shows the real output');
    }
}
