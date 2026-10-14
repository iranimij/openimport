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
