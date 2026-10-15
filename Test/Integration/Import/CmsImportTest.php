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
use Iranimij\OpenImport\Model\Import\Cms\CmsRecords;
use Iranimij\OpenImport\Model\Reader\ReaderFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * CMS pages and blocks through OpenImport's own importer: create, preview, update, export round trip, delete.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class CmsImportTest extends TestCase
{
    private function file(string $content): string
    {
        $var = Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryWrite(DirectoryList::VAR_DIR);
        $relative = 'openimport/it-cms-' . bin2hex(random_bytes(3)) . '.csv';
        $var->writeFile($relative, $content);

        return $var->getAbsolutePath($relative);
    }

    private function profile(string $entity, string $behavior = ProfileInterface::BEHAVIOR_ADD_UPDATE): ProfileInterface
    {
        $mapping = [
            ['target' => 'identifier', 'source' => 'identifier'],
            ['target' => 'title', 'source' => 'title'],
            ['target' => 'content', 'source' => 'content'],
            ['target' => 'is_active', 'source' => 'active'],
            ['target' => 'stores', 'source' => 'stores'],
        ];
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('CMS ' . uniqid())
            ->setEntity($entity)
            ->setFormat('csv')
            ->setSourceType('upload')
            ->setBehavior($behavior)
            ->setSafetyThreshold(100)
            ->setMapping($mapping);

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    private function process(ProfileInterface $profile, string $file, string $mode = RunInterface::MODE_LIVE, array $options = []): RunInterface
    {
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue(
            $profile,
            $mode,
            RunInterface::TRIGGER_CLI,
            $options + ['file' => $file, 'original_name' => 'cms.csv']
        );

        return Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
    }

    public function testPagesAndBlocks(): void
    {
        $tag = 'oi-' . bin2hex(random_bytes(3));
        $records = Bootstrap::getObjectManager()->get(CmsRecords::class);
        foreach ([EntityRegistry::CMS_PAGE => CmsRecords::PAGE, EntityRegistry::CMS_BLOCK => CmsRecords::BLOCK] as $entity => $kind) {
            $profile = $this->profile($entity);
            $file = $this->file("identifier,title,content,active,stores\n{$tag}-about,About us,<p>Hello</p>,1,all\n{$tag}-terms,Terms,<p>Terms</p>,0,default\n");

            $dry = $this->process($profile, $file, RunInterface::MODE_DRY_RUN);
            self::assertSame(RunInterface::STATUS_SUCCESS, $dry->getStatus(), $kind . ': ' . $dry->getMessage());
            self::assertSame(2, $dry->getCount('created'));
            self::assertNull($records->find($kind, "{$tag}-about", []), 'a dry run writes nothing');

            $live = $this->process($profile, $file);
            self::assertSame(RunInterface::STATUS_SUCCESS, $live->getStatus(), $kind . ': ' . $live->getMessage());
            self::assertSame(2, $live->getCount('created'));
            $about = $records->find($kind, "{$tag}-about", []);
            self::assertNotNull($about);
            self::assertSame('<p>Hello</p>', $about->getData('content'));
            self::assertSame([0], $records->storesOf($about));
            $terms = $records->find($kind, "{$tag}-terms", []);
            self::assertFalse((bool) $terms->getData('is_active'));
            self::assertSame([1], $records->storesOf($terms));

            $update = $this->file("identifier,title,content,active,stores\n{$tag}-about,About us,<p>Hello world</p>,1,all\n");
            $dry2 = $this->process($profile, $update, RunInterface::MODE_DRY_RUN);
            self::assertSame(1, $dry2->getCount('updated'), $kind . ': ' . $dry2->getMessage());
            $live2 = $this->process($profile, $update);
            self::assertSame(RunInterface::STATUS_SUCCESS, $live2->getStatus(), $live2->getMessage());
            self::assertSame('<p>Hello world</p>', $records->find($kind, "{$tag}-about", [])->getData('content'));

            $export = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
                ->setName('CMS export ' . uniqid())
                ->setDirection(ProfileInterface::DIRECTION_EXPORT)
                ->setEntity($entity)
                ->setFormat('csv')
                ->setRowFilters(['mode' => 'include', 'match' => 'all', 'conditions' => [['column' => 'identifier', 'operator' => 'starts_with', 'value' => $tag]]])
                ->setExportOptions(['destination' => 'download']);
            $export = Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($export);
            $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue($export, RunInterface::MODE_LIVE, RunInterface::TRIGGER_CLI);
            $run = Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
            self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $run->getMessage());
            $path = Bootstrap::getObjectManager()->get(RunFiles::class)->absolute((string) $run->getOutputFile());
            $rows = [];
            foreach (Bootstrap::getObjectManager()->get(ReaderFactory::class)->create($path, 'csv')->rows() as $row) {
                $rows[$row['identifier']] = $row;
            }
            self::assertSame(['all'], [$rows["{$tag}-about"]['stores'] ?? null]);
            self::assertSame('default', $rows["{$tag}-terms"]['stores'] ?? null);

            $delete = $this->profile($entity, ProfileInterface::BEHAVIOR_DELETE);
            $gone = $this->process($delete, $this->file("identifier,title,content,active,stores\n{$tag}-about,,,,\n{$tag}-terms,,,,\n"), RunInterface::MODE_LIVE, ['confirmed' => true]);
            self::assertSame(RunInterface::STATUS_SUCCESS, $gone->getStatus(), $gone->getMessage());
            self::assertSame(2, $gone->getCount('deleted'));
            self::assertNull($records->find($kind, "{$tag}-about", []));
        }
    }

    public function testBadRows(): void
    {
        $profile = $this->profile(EntityRegistry::CMS_PAGE);
        $run = $this->process($profile, $this->file("identifier,title,content,active,stores\nbad id!,X,,1,all\n"));
        self::assertSame(RunInterface::STATUS_FAILED, $run->getStatus());
        self::assertStringContainsString('letters, digits', $run->getMessage());

        $run = $this->process($profile, $this->file("identifier,title,content,active,stores\noi-notitle-" . bin2hex(random_bytes(2)) . ",,x,1,all\n"));
        self::assertSame(RunInterface::STATUS_FAILED, $run->getStatus());
        self::assertStringContainsString('needs a title', $run->getMessage());

        $run = $this->process($profile, $this->file("identifier,title,content,active,stores\noi-x,X,x,1,nowhere\n"));
        self::assertSame(RunInterface::STATUS_FAILED, $run->getStatus());
        self::assertStringContainsString('no store view', $run->getMessage());
    }
}
