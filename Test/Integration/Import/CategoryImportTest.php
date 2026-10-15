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
use Iranimij\OpenImport\Model\Execution\RunProcessor;
use Iranimij\OpenImport\Model\Execution\RunQueue;
use Iranimij\OpenImport\Model\Import\Category\CategoryTree;
use Iranimij\OpenImport\Model\Reader\ReaderFactory;
use Iranimij\OpenImport\Model\Storage\PreviewStore;
use Iranimij\OpenImport\Model\Execution\RunFiles;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Store\Model\Store;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Categories through OpenImport's own importer: create a branch from paths, update default and store-view values,
 * preview before writing, delete a branch, export and read back.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class CategoryImportTest extends TestCase
{
    private ?string $root = null;

    protected function setUp(): void
    {
        $tree = Bootstrap::getObjectManager()->get(CategoryTree::class);
        $tree->reset();
        foreach ($tree->all() as $path) {
            // The first root category of the shop (level 1): "Default Category" in the test store.
            $this->root = $path;
            break;
        }
        self::assertNotNull($this->root, 'the shop has a root category');
    }

    private function file(string $content): string
    {
        $var = Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryWrite(DirectoryList::VAR_DIR);
        $relative = 'openimport/it-cat-' . bin2hex(random_bytes(3)) . '.csv';
        $var->writeFile($relative, $content);

        return $var->getAbsolutePath($relative);
    }

    private function profile(string $behavior = ProfileInterface::BEHAVIOR_ADD_UPDATE, array $mapping = []): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Categories ' . uniqid())
            ->setEntity(EntityRegistry::CATEGORY)
            ->setFormat('csv')
            ->setSourceType('upload')
            ->setBehavior($behavior)
            ->setSafetyThreshold(100)
            ->setMapping($mapping !== [] ? $mapping : [
                ['target' => 'path', 'source' => 'path'],
                ['target' => 'name', 'source' => 'name'],
                ['target' => 'is_active', 'source' => 'active'],
                ['target' => 'description', 'source' => 'description'],
                ['target' => 'store:default:name', 'source' => 'name_default'],
            ]);

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    private function process(ProfileInterface $profile, string $file, string $mode = RunInterface::MODE_LIVE, array $options = []): RunInterface
    {
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue(
            $profile,
            $mode,
            RunInterface::TRIGGER_CLI,
            $options + ['file' => $file, 'original_name' => 'categories.csv']
        );

        return Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
    }

    public function testCreateUpdatePreviewDeleteAndExport(): void
    {
        $tag = 'OI' . bin2hex(random_bytes(2));
        $root = $this->root;
        $file = $this->file(
            "path,name,active,description,name_default\n"
            . "{$root}/{$tag} Men/Shoes,Shoes,1,All shoes,Schuhe\n"
            . "{$root}/{$tag} Men/Shirts,Shirts,0,,\n"
        );
        $profile = $this->profile();

        $dry = $this->process($profile, $file, RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_SUCCESS, $dry->getStatus(), $dry->getMessage());
        self::assertSame(2, $dry->getCount('created'), 'two new categories in the preview (the parent is created on the way)');
        $tree = Bootstrap::getObjectManager()->get(CategoryTree::class);
        $tree->reset();
        self::assertNull($tree->id("{$root}/{$tag} Men/Shoes"), 'a dry run writes nothing');

        $live = $this->process($profile, $file);
        self::assertSame(RunInterface::STATUS_SUCCESS, $live->getStatus(), $live->getMessage());
        self::assertSame(3, $live->getCount('created'), 'the parent "Men" plus two children');
        $tree->reset();
        $shoesId = $tree->id("{$root}/{$tag} Men/Shoes");
        self::assertNotNull($shoesId);
        $categories = Bootstrap::getObjectManager()->get(CategoryRepositoryInterface::class);
        $shoes = $categories->get($shoesId, Store::DEFAULT_STORE_ID);
        self::assertSame('Shoes', $shoes->getName());
        self::assertSame('All shoes', $shoes->getDescription());
        self::assertTrue((bool) $shoes->getIsActive());
        self::assertSame('Schuhe', $categories->get($shoesId, 1)->getName(), 'store-view name from the store:default column');
        $shirts = $categories->get((int) $tree->id("{$root}/{$tag} Men/Shirts"), Store::DEFAULT_STORE_ID);
        self::assertFalse((bool) $shirts->getIsActive());
        self::assertSame((int) $tree->id("{$root}/{$tag} Men"), (int) $shirts->getParentId());

        // Update: the preview shows the changed field, the run changes it, an unchanged row counts as unchanged.
        $update = $this->file("path,name,active,description,name_default\n{$root}/{$tag} Men/Shoes,Shoes,1,Every shoe,Schuhe\n{$root}/{$tag} Men/Shirts,Shirts,0,,\n");
        $dry2 = $this->process($profile, $update, RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_SUCCESS, $dry2->getStatus(), $dry2->getMessage());
        self::assertSame(1, $dry2->getCount('updated'), $dry2->getMessage());
        $preview = Bootstrap::getObjectManager()->get(PreviewStore::class)->fetch((int) $dry2->getRunId(), 10, 0);
        $changes = [];
        foreach ($preview as $record) {
            foreach ($record->diff as $field => $change) {
                $changes[$field] = $change;
            }
        }
        self::assertSame(['All shoes', 'Every shoe'], $changes['description'] ?? null, json_encode($changes));
        $live2 = $this->process($profile, $update);
        self::assertSame(RunInterface::STATUS_SUCCESS, $live2->getStatus(), $live2->getMessage());
        self::assertSame('Every shoe', $categories->get($shoesId, Store::DEFAULT_STORE_ID)->getDescription());

        // Export: the branch comes back as paths, with the store-view name as its own row.
        $export = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Category export ' . uniqid())
            ->setDirection(ProfileInterface::DIRECTION_EXPORT)
            ->setEntity(EntityRegistry::CATEGORY)
            ->setFormat('csv')
            ->setRowFilters(['mode' => 'include', 'match' => 'all', 'conditions' => [['column' => 'path', 'operator' => 'contains', 'value' => $tag]]])
            ->setExportOptions(['destination' => 'download']);
        $export = Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($export);
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue($export, RunInterface::MODE_LIVE, RunInterface::TRIGGER_CLI);
        $run = Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $run->getMessage());
        $path = Bootstrap::getObjectManager()->get(RunFiles::class)->absolute((string) $run->getOutputFile());
        $rows = [];
        foreach (Bootstrap::getObjectManager()->get(ReaderFactory::class)->create($path, 'csv')->rows() as $row) {
            $rows[$row['path'] . '|' . $row['store_view_code']] = $row;
        }
        self::assertSame(4, count($rows), 'Men, Shoes, Shirts and the German Shoes row: ' . implode(', ', array_keys($rows)));
        self::assertSame('Every shoe', $rows["{$root}/{$tag} Men/Shoes|"]['description']);
        self::assertSame('Schuhe', $rows["{$root}/{$tag} Men/Shoes|default"]['name']);

        // Delete the branch: the parent takes its children with it.
        $delete = $this->profile(ProfileInterface::BEHAVIOR_DELETE, [['target' => 'path', 'source' => 'path']]);
        $gone = $this->process($delete, $this->file("path\n{$root}/{$tag} Men\n"), RunInterface::MODE_LIVE, ['confirmed' => true]);
        self::assertSame(RunInterface::STATUS_SUCCESS, $gone->getStatus(), $gone->getMessage());
        self::assertSame(3, $gone->getCount('deleted'));
        $tree->reset();
        self::assertNull($tree->id("{$root}/{$tag} Men/Shoes"));
    }

    public function testBadRowsAreReportedAndReplaceIsRefused(): void
    {
        $root = $this->root;
        $profile = $this->profile();
        $run = $this->process($profile, $this->file("path,name,active,description,name_default\nNope/Men,Men,maybe,,\n"));
        self::assertSame(RunInterface::STATUS_FAILED, $run->getStatus());
        self::assertStringContainsString('not a root category', $run->getMessage());

        $replace = $this->profile(ProfileInterface::BEHAVIOR_REPLACE);
        $run = $this->process($replace, $this->file("path,name,active,description,name_default\n{$root}/X,X,1,,\n"));
        self::assertSame(RunInterface::STATUS_FAILED, $run->getStatus());
        self::assertStringContainsString('Replace', $run->getMessage());
    }
}
