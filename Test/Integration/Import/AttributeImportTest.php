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
use Iranimij\OpenImport\Model\Import\Attribute\AttributeOptions;
use Iranimij\OpenImport\Model\Import\Attribute\AttributeSets;
use Iranimij\OpenImport\Model\Reader\ReaderFactory;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Product attributes through OpenImport's own importer: create with options and set assignment, preview, update
 * options and store labels, export round trip, take out of a set with Delete.
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
#[AppIsolation(true)]
class AttributeImportTest extends TestCase
{
    private function file(string $content): string
    {
        $var = Bootstrap::getObjectManager()->get(Filesystem::class)->getDirectoryWrite(DirectoryList::VAR_DIR);
        $relative = 'openimport/it-attr-' . bin2hex(random_bytes(3)) . '.csv';
        $var->writeFile($relative, $content);

        return $var->getAbsolutePath($relative);
    }

    private function profile(string $behavior = ProfileInterface::BEHAVIOR_ADD_UPDATE): ProfileInterface
    {
        $profile = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Attributes ' . uniqid())
            ->setEntity(EntityRegistry::ATTRIBUTE)
            ->setFormat('csv')
            ->setSourceType('upload')
            ->setBehavior($behavior)
            ->setSafetyThreshold(100)
            ->setMapping([
                ['target' => 'attribute_code', 'source' => 'code'],
                ['target' => 'label', 'source' => 'label'],
                ['target' => 'frontend_input', 'source' => 'type'],
                ['target' => 'options', 'source' => 'options'],
                ['target' => 'is_required', 'source' => 'required'],
                ['target' => 'attribute_set', 'source' => 'sets'],
                ['target' => 'attribute_group', 'source' => null, 'static' => 'OpenImport'],
                ['target' => 'store:default:label', 'source' => 'label_default'],
                ['target' => 'store:default:options', 'source' => 'options_default'],
            ]);

        return Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($profile);
    }

    private function process(ProfileInterface $profile, string $file, string $mode = RunInterface::MODE_LIVE, array $options = []): RunInterface
    {
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue(
            $profile,
            $mode,
            RunInterface::TRIGGER_CLI,
            $options + ['file' => $file, 'original_name' => 'attributes.csv']
        );

        return Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
    }

    /**
     * @var string[] attribute codes to remove after the test, whatever happened
     */
    private ?array $created = []; // nullable: PHPUnit 9 nulls typed properties

    protected function tearDown(): void
    {
        $attributes = Bootstrap::getObjectManager()->get(ProductAttributeRepositoryInterface::class);
        foreach ($this->created ?? [] as $code) {
            try {
                $attributes->deleteById($code);
            } catch (\Exception $exception) {
                // already gone
            }
        }
        $this->created = [];
    }

    public function testCreateUpdateExportAndUnassign(): void
    {
        $code = 'oi_' . bin2hex(random_bytes(3));
        $this->created = [$code, $code . '_note'];
        $header = "code,label,type,options,required,sets,label_default,options_default\n";
        $file = $this->file($header . "{$code},Material,select,Cotton|Wool,0,Default,Stoff,Baumwolle|Wolle\n{$code}_note,Note,text,,1,,,\n");
        $profile = $this->profile();

        $dry = $this->process($profile, $file, RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_SUCCESS, $dry->getStatus(), $dry->getMessage());
        self::assertSame(2, $dry->getCount('created'));
        $attributes = Bootstrap::getObjectManager()->get(ProductAttributeRepositoryInterface::class);
        try {
            $attributes->get($code);
            self::fail('a dry run creates nothing');
        } catch (\Magento\Framework\Exception\NoSuchEntityException $exception) {
            self::assertTrue(true);
        }

        $live = $this->process($profile, $file);
        self::assertSame(RunInterface::STATUS_SUCCESS, $live->getStatus(), $live->getMessage());
        self::assertSame(2, $live->getCount('created'));
        $material = $attributes->get($code);
        self::assertSame('select', $material->getFrontendInput());
        self::assertSame('Material', $material->getDefaultFrontendLabel());
        $options = Bootstrap::getObjectManager()->get(AttributeOptions::class);
        self::assertSame(['Cotton', 'Wool'], $options->labels((int) $material->getAttributeId()));
        self::assertSame(['Baumwolle', 'Wolle'], $options->storeLabels((int) $material->getAttributeId(), 1), 'store labels of the default store view');
        self::assertSame('Stoff', $options->attributeLabels((int) $material->getAttributeId())[1] ?? null, 'store-view label');
        self::assertTrue((bool) $attributes->get($code . '_note')->getIsRequired());
        $sets = Bootstrap::getObjectManager()->get(AttributeSets::class);
        $sets->reset();
        $defaultSet = (int) $sets->id('Default');
        self::assertTrue($sets->has($defaultSet, $code), 'assigned to the Default set');

        // Update: a new option is added, existing ones keep their ids; the preview shows the option change.
        $update = $this->file($header . "{$code},Material,select,Cotton|Wool|Silk,0,Default,,\n");
        $dry2 = $this->process($profile, $update, RunInterface::MODE_DRY_RUN);
        self::assertSame(RunInterface::STATUS_SUCCESS, $dry2->getStatus(), $dry2->getMessage());
        self::assertSame(1, $dry2->getCount('updated'), $dry2->getMessage());
        $before = array_flip(array_map(static fn (array $o): string => $o['label'], $options->load((int) $material->getAttributeId())));
        $live2 = $this->process($profile, $update);
        self::assertSame(RunInterface::STATUS_SUCCESS, $live2->getStatus(), $live2->getMessage());
        $after = array_flip(array_map(static fn (array $o): string => $o['label'], $options->load((int) $material->getAttributeId())));
        self::assertSame(['Cotton', 'Wool', 'Silk'], array_keys($after));
        self::assertSame($before['Cotton'], $after['Cotton'], 'existing options keep their ids');
        self::assertSame(['Baumwolle', 'Wolle', ''], $options->storeLabels((int) $material->getAttributeId(), 1), 'adding an option keeps the store labels');
        self::assertSame('Stoff', $options->attributeLabels((int) $material->getAttributeId())[1] ?? null, 'an update keeps the store-view label');

        // Export the two attributes and read them back.
        $export = Bootstrap::getObjectManager()->get(ProfileInterfaceFactory::class)->create()
            ->setName('Attribute export ' . uniqid())
            ->setDirection(ProfileInterface::DIRECTION_EXPORT)
            ->setEntity(EntityRegistry::ATTRIBUTE)
            ->setFormat('csv')
            ->setRowFilters(['mode' => 'include', 'match' => 'all', 'conditions' => [['column' => 'attribute_code', 'operator' => 'starts_with', 'value' => $code]]])
            ->setExportOptions(['destination' => 'download']);
        $export = Bootstrap::getObjectManager()->get(ProfileRepositoryInterface::class)->save($export);
        $run = Bootstrap::getObjectManager()->get(RunQueue::class)->queue($export, RunInterface::MODE_LIVE, RunInterface::TRIGGER_CLI);
        $run = Bootstrap::getObjectManager()->get(RunProcessor::class)->process((int) $run->getRunId());
        self::assertSame(RunInterface::STATUS_SUCCESS, $run->getStatus(), $run->getMessage());
        $path = Bootstrap::getObjectManager()->get(RunFiles::class)->absolute((string) $run->getOutputFile());
        $rows = [];
        foreach (Bootstrap::getObjectManager()->get(ReaderFactory::class)->create($path, 'csv')->rows() as $row) {
            $rows[$row['attribute_code'] . '|' . $row['store_view_code']] = $row;
        }
        self::assertSame('Cotton|Wool|Silk', $rows["{$code}|"]['options'] ?? null, implode(', ', array_keys($rows)));
        self::assertSame('select', $rows["{$code}|"]['frontend_input']);
        self::assertStringContainsString('Default', $rows["{$code}|"]['attribute_set']);
        self::assertSame('Stoff', $rows["{$code}|default"]['label'] ?? null);
        self::assertSame('Baumwolle|Wolle|', $rows["{$code}|default"]['options'] ?? null);

        // Delete takes the attribute out of the set; the attribute itself stays.
        $unassign = $this->profile(ProfileInterface::BEHAVIOR_DELETE);
        $gone = $this->process($unassign, $this->file($header . "{$code},,,,,Default,,\n"), RunInterface::MODE_LIVE, ['confirmed' => true]);
        self::assertSame(RunInterface::STATUS_SUCCESS, $gone->getStatus(), $gone->getMessage());
        $sets->reset();
        self::assertFalse($sets->has($defaultSet, $code));
        self::assertSame('Material', $attributes->get($code)->getDefaultFrontendLabel());

    }

    public function testBadRows(): void
    {
        $profile = $this->profile();
        $header = "code,label,type,options,required,sets,label_default,options_default\n";
        $run = $this->process($profile, $this->file($header . "Bad Code,X,text,,0,,,\n"));
        self::assertSame(RunInterface::STATUS_FAILED, $run->getStatus());
        self::assertStringContainsString('lower-case', $run->getMessage());

        $run = $this->process($profile, $this->file($header . "oi_nolabel,,text,,0,,,\n"));
        self::assertSame(RunInterface::STATUS_FAILED, $run->getStatus());
        self::assertStringContainsString('needs a label', $run->getMessage());

        $this->created = ['oi_nolabel', 'oi_badset'];
        $run = $this->process($profile, $this->file($header . "oi_badset,Bad set,text,,0,Nope,,\n"));
        self::assertSame(RunInterface::STATUS_FAILED, $run->getStatus());
        self::assertStringContainsString('no attribute set', $run->getMessage());
    }
}
