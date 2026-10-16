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
use Iranimij\OpenImport\Api\RunRepositoryInterface;
use Iranimij\OpenImport\Model\Admin\ProfileFormMapper;
use Iranimij\OpenImport\Model\Execution\RunProcessor;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Iranimij\OpenImport\Model\Execution\Source\FolderSource;
use Iranimij\OpenImport\Model\Execution\Source\UrlSource;
use Iranimij\OpenImport\Model\Execution\SourceResolver;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Security\Secrets;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Profile sources: server folders (sandboxed, pattern, newest file, move or delete after success), the URL guard,
 * and secrets that are stored encrypted and never come back.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class SourcesTest extends TestCase
{
    private function var(): \Magento\Framework\Filesystem\Directory\WriteInterface
    {
        return Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryWrite(DirectoryList::VAR_DIR);
    }

    /**
     * @param string $type
     * @param array<string, mixed> $config
     * @param string $afterSuccess
     * @return ProfileInterface
     */
    private function profile(string $type, array $config, string $afterSuccess = 'keep'): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Source ' . $type . ' ' . uniqid())
            ->setEntity('catalog_product')
            ->setFormat('csv')
            ->setSourceType($type)
            ->setSourceConfig($config)
            ->setAfterSuccess($afterSuccess)
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

    public function testFolderSourceReadsTheNewestMatchingFileAndMovesItAfterSuccess(): void
    {
        $tag = bin2hex(random_bytes(3));
        $folder = 'openimport/it-' . $tag;
        $var = $this->var();
        $var->writeFile($folder . '/stock-old.csv', "sku,name,price\nOI-SRC-{$tag}-OLD,Old {$tag},1\n");
        $var->writeFile($folder . '/stock-new.csv', "sku,name,price\nOI-SRC-{$tag}-NEW,New {$tag},2\n");
        $var->writeFile($folder . '/notes.txt', "not a feed\n");
        $var->getDriver()->touch($var->getAbsolutePath($folder . '/stock-old.csv'), time() - 3600);
        $var->getDriver()->touch($var->getAbsolutePath($folder . '/stock-new.csv'), time() - 60);

        $profile = $this->profile('folder', ['path' => 'it-' . $tag, 'pattern' => 'stock-*.csv', 'pick' => 'newest'], 'move');
        $resolver = Bootstrap::getObjectManager()->get(SourceResolver::class);
        self::assertStringContainsString('the next run reads stock-new.csv', $resolver->source($profile)->test($profile));

        $dry = $this->process($profile, RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_SUCCESS, $dry->getStatus(), $dry->getMessage());
        self::assertTrue($var->isExist($folder . '/stock-new.csv'), 'a dry run leaves the file where it is');

        $live = $this->process($profile);
        self::assertSame(RunInterface::STATUS_SUCCESS, $live->getStatus(), $live->getMessage());
        self::assertSame(1, $live->getCount('created'));
        self::assertFalse($var->isExist($folder . '/stock-new.csv'));
        self::assertTrue($var->isExist($folder . '/processed/stock-new.csv'));
        self::assertTrue($var->isExist($folder . '/stock-old.csv'));

        $delete = $this->profile('folder', ['path' => 'it-' . $tag, 'pattern' => 'stock-*.csv'], 'delete');
        $second = $this->process($delete);
        self::assertSame(RunInterface::STATUS_SUCCESS, $second->getStatus(), $second->getMessage());
        self::assertFalse($var->isExist($folder . '/stock-old.csv'));

        $empty = $this->process($delete);
        self::assertSame(RunInterface::STATUS_FAILED, $empty->getStatus());
        self::assertStringContainsString('No file matches "stock-*.csv" (1 file(s) in the folder)', $empty->getMessage());
    }

    public function testAllMatchingFilesRunOneRunEach(): void
    {
        $tag = bin2hex(random_bytes(3));
        $folder = 'openimport/it-all-' . $tag;
        $var = $this->var();
        $var->writeFile($folder . '/stock-2.csv', "sku,name,price\nOI-ALL-{$tag}-2,Two {$tag},2\n");
        $var->writeFile($folder . '/stock-10.csv', "sku,name,price\nOI-ALL-{$tag}-10,Ten {$tag},10\n");
        $var->writeFile($folder . '/stock-1.csv', "sku,name\nOI-ALL-{$tag}-1,One {$tag}\n");
        $profile = $this->profile('folder', ['path' => 'it-all-' . $tag, 'pattern' => 'stock-*.csv', 'pick' => 'all'], 'move');
        $processor = Bootstrap::getObjectManager()->get(RunProcessor::class);
        $runs = Bootstrap::getObjectManager()->get(RunRepositoryInterface::class);

        $first = $this->process($profile);
        // stock-1.csv has no price column: the mapping needs it, so that file fails and stays.
        self::assertSame(RunInterface::STATUS_FAILED, $first->getStatus(), $first->getMessage());
        self::assertSame('stock-1.csv', $first->getOptions()['remote_file'] ?? null);
        self::assertMatchesRegularExpression('/File 1 of 3: stock-1\.csv\. The other files are queued as runs (\d+), (\d+)\./', $first->getMessage());
        preg_match('/queued as runs (\d+), (\d+)\./', $first->getMessage(), $ids);
        $second = $processor->process((int) $ids[1]);
        $third = $processor->process((int) $ids[2]);
        self::assertSame(['stock-2.csv', 'stock-10.csv'], [$second->getOptions()['remote_file'] ?? null,
            $third->getOptions()['remote_file'] ?? null], 'name order, numbers as numbers');
        self::assertSame(RunInterface::STATUS_SUCCESS, $second->getStatus(), $second->getMessage());
        self::assertSame(RunInterface::STATUS_SUCCESS, $third->getStatus(), $third->getMessage());
        self::assertTrue($var->isExist($folder . '/stock-1.csv'), 'the failed file stays');
        self::assertTrue($var->isExist($folder . '/processed/stock-2.csv'));
        self::assertTrue($var->isExist($folder . '/processed/stock-10.csv'));
        self::assertSame((int) $first->getRunId(), (int) ($runs->getById((int) $ids[2])->getOptions()['batch_of'] ?? 0));
    }

    public function testAllMatchingFilesCanStopAtAFailedFile(): void
    {
        $tag = bin2hex(random_bytes(3));
        $folder = 'openimport/it-stop-' . $tag;
        $var = $this->var();
        $var->writeFile($folder . '/a.csv', "sku,name\nOI-STOP-{$tag}-A,A\n");
        $var->writeFile($folder . '/b.csv', "sku,name,price\nOI-STOP-{$tag}-B,B {$tag},1\n");
        $profile = $this->profile(
            'folder',
            ['path' => 'it-stop-' . $tag, 'pattern' => '*.csv', 'pick' => 'all', 'all_on_error' => 'stop'],
            'move'
        );

        $first = $this->process($profile);
        self::assertSame(RunInterface::STATUS_FAILED, $first->getStatus());
        preg_match('/queued as runs (\d+)\./', $first->getMessage(), $ids);
        $next = Bootstrap::getObjectManager()->get(RunRepositoryInterface::class)->getById((int) $ids[1]);
        self::assertSame(RunInterface::STATUS_CANCELLED, $next->getStatus(), 'b.csv waits for the next run');
        self::assertTrue($var->isExist($folder . '/b.csv'));

        // The next run starts again at the first file: nothing is lost.
        $again = $this->process($profile);
        self::assertSame('a.csv', $again->getOptions()['remote_file'] ?? null);
    }

    public function testFolderSourceStaysInsideTheAllowedFolders(): void
    {
        $source = Bootstrap::getObjectManager()->get(FolderSource::class);
        $factory = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class);
        foreach (['../../app/etc', '/etc', '/tmp'] as $path) {
            // Not saved: the validator refuses ".." already; the source itself must refuse the rest.
            $profile = $factory->create()->setSourceType('folder')->setSourceConfig(['path' => $path]);
            try {
                $source->directory($profile);
                self::fail($path . ' must be refused');
            } catch (PipelineException $exception) {
                self::assertMatchesRegularExpression('/outside the allowed folders|may not contain|does not exist/', $exception->getMessage(), $path);
            }
        }
    }

    public function testUrlSourceRefusesPrivateAddressesAndRewritesGoogleSheetsLinks(): void
    {
        $source = Bootstrap::getObjectManager()->get(UrlSource::class);
        $profile = $this->profile('url', ['url' => 'http://127.0.0.1/feed.csv']);
        try {
            $source->test($profile);
            self::fail('A loopback address must be refused.');
        } catch (PipelineException $exception) {
            self::assertStringContainsString('private or local address', $exception->getMessage());
        }

        self::assertSame(
            'https://docs.google.com/spreadsheets/d/1AbC_def/export?format=csv&gid=42',
            $source->url(['url' => 'https://docs.google.com/spreadsheets/d/1AbC_def/edit?usp=sharing#gid=42'])
        );
        self::assertSame(
            'https://docs.google.com/spreadsheets/d/e/2PACX-abc/pub?output=csv',
            $source->url(['url' => 'https://docs.google.com/spreadsheets/d/e/2PACX-abc/pub'])
        );
        self::assertSame('https://example.com/feed.csv', $source->url(['url' => ' https://example.com/feed.csv ']));
    }

    public function testSecretsAreStoredEncryptedAndNeverShown(): void
    {
        $mapper = Bootstrap::getObjectManager()->get(ProfileFormMapper::class);
        $secrets = Bootstrap::getObjectManager()->get(Secrets::class);
        $profile = $mapper->fromForm([
            'name' => 'Secret ' . uniqid(),
            'entity' => 'catalog_product',
            'source_type' => 'sftp',
            'source_config' => ['host' => 'files.example.com', 'username' => 'shop', 'password' => 'hunter2'],
        ], Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create());
        $profile = Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
        $stored = Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->getById((int) $profile->getProfileId());

        $config = $stored->getSourceConfig();
        self::assertArrayHasKey('secrets', $config);
        self::assertNotSame('hunter2', $config['secrets']['password']);
        self::assertStringNotContainsString('hunter2', json_encode($config) ?: '');
        self::assertSame('hunter2', $secrets->get($config, 'password'));

        $form = $mapper->toForm($stored);
        self::assertArrayNotHasKey('secrets', $form['source_config']);
        self::assertSame('1', $form['source_config']['password_stored']);

        $kept = $mapper->fromForm(['source_config' => ['host' => 'other.example.com', 'password' => '']], $stored);
        self::assertSame('hunter2', $secrets->get($kept->getSourceConfig(), 'password'), 'an empty field keeps the password');
        $changed = $mapper->fromForm(['source_config' => ['password' => 'new-one']], $stored);
        self::assertSame('new-one', $secrets->get($changed->getSourceConfig(), 'password'));
    }
}
