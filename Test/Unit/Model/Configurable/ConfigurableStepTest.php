<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Configurable;

use Iranimij\OpenImport\Model\Configurable\ConfigurableStep;
use Iranimij\OpenImport\Model\Configurable\ParentBuilder;
use Iranimij\OpenImport\Model\Entity\RecordReaderPool;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Pipeline\Step\StepRecord;
use Iranimij\OpenImport\Test\Unit\Model\Configurable\Fake\FakeConfigurableAttributes;
use Iranimij\OpenImport\Test\Unit\Model\Configurable\Fake\FakeUrlKey;
use Iranimij\OpenImport\Test\Unit\Model\Image\Fake\FakeImageColumns;
use Iranimij\OpenImport\Test\Unit\Model\Pipeline\Fake\FakeRecordReader;
use Iranimij\OpenImport\Test\Unit\Model\ProfileStub;
use PHPUnit\Framework\TestCase;

/**
 * Configurable products from simple rows: settings, columns, variants, parents.
 */
class ConfigurableStepTest extends TestCase
{
    private const COLUMNS = ['sku', 'name', 'price', 'color', 'size', 'base_image', 'product_type', 'visibility',
        'configurable:parent_sku', 'configurable:parent_name'];

    private function step(): ConfigurableStep
    {
        return new ConfigurableStep(
            new FakeConfigurableAttributes(),
            new ParentBuilder(),
            // TS-RED-M exists already: it keeps its URL key.
            new RecordReaderPool(['catalog_product' => new FakeRecordReader(['ts-red-m' => ['name' => 'x']], [], 1)]),
            new FakeImageColumns(),
            new FakeUrlKey()
        );
    }

    /**
     * @param array<string, string> $options
     * @param string $entity
     * @return ProfileStub
     */
    private function profile(array $options = [], string $entity = 'catalog_product'): ProfileStub
    {
        return new ProfileStub(['entity' => $entity, 'import_options' => $options + [
            'configurable' => '1',
            'configurable_attributes' => 'color, size',
        ]]);
    }

    /**
     * @param int $line
     * @param array<string, string> $values
     * @param array<string, string> $store store-view row values (de)
     * @return StepRecord
     */
    private function row(int $line, array $values, array $store = []): StepRecord
    {
        $empty = array_fill_keys(self::COLUMNS, '');
        $rows = [array_merge($empty, ['product_type' => 'simple', 'store_view_code' => ''], $values)];
        if ($store !== []) {
            $rows[] = array_merge($empty, ['sku' => $values['sku'], 'store_view_code' => 'de'], $store);
        }

        return new StepRecord($line, $values['sku'], $rows);
    }

    /**
     * observe() then apply() as the runner calls them.
     *
     * @param ConfigurableStep $step
     * @param StepRecord[] $records
     * @return void
     */
    private function batch(ConfigurableStep $step, array $records): void
    {
        $step->observe(array_map(
            static fn (StepRecord $record): StepRecord => new StepRecord($record->line, $record->identifier, $record->rows),
            $records
        ));
        $step->apply($records, static function (): void {
        });
    }

    public function testWhenTheStepTakesPart(): void
    {
        $step = $this->step();
        self::assertTrue($step->begin($this->profile(), 'add_update', false));
        self::assertFalse($step->begin($this->profile(['configurable' => '']), 'add_update', false));
        self::assertFalse($step->begin($this->profile([], 'customer'), 'add_update', false));
        self::assertFalse($step->begin($this->profile(), 'delete', false));
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function wrongSettings(): array
    {
        return [
            'no attributes' => [['configurable_attributes' => ' , '], 'Choose the variation attributes'],
            'unknown' => [['configurable_attributes' => 'colour'], 'There is no product attribute "colour".'],
            'not global' => [['configurable_attributes' => 'color,material'], '"material" has no global scope'],
        ];
    }

    /**
     * @param array<string, string> $options
     * @param string $message
     * @dataProvider wrongSettings
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('wrongSettings')]
    public function testWrongSettingsStopTheRun(array $options, string $message): void
    {
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage($message);
        $this->step()->begin($this->profile($options), 'add_update', false);
    }

    public function testColumnsCoreReceives(): void
    {
        $step = $this->step();
        $step->begin($this->profile(), 'add_update', false);
        self::assertSame(
            ['sku', 'name', 'price', 'color', 'size', 'base_image', 'product_type', 'visibility',
                'configurable_variations', 'url_key'],
            $step->columns(self::COLUMNS)
        );
    }

    /**
     * @return array<string, array{0: string[], 1: string}>
     */
    public static function missingColumns(): array
    {
        return [
            'parent SKU' => [['sku', 'color', 'size'], 'Map a column to "Parent SKU (configurable products)"'],
            'attribute' => [['sku', 'color', 'configurable:parent_sku'], 'Map the variation attribute "size"'],
        ];
    }

    /**
     * @param string[] $columns
     * @param string $message
     * @dataProvider missingColumns
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('missingColumns')]
    public function testMissingColumnsStopTheRun(array $columns, string $message): void
    {
        $step = $this->step();
        $step->begin($this->profile(), 'add_update', false);
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage($message);
        $step->columns($columns);
    }

    public function testVariantsBecomeOneParent(): void
    {
        $step = $this->step();
        $step->begin($this->profile(), 'add_update', false);
        $step->columns(self::COLUMNS);
        $red = $this->row(2, ['sku' => 'TS-RED-M', 'name' => 'T-Shirt Basic Red M', 'price' => '19.90', 'color' => 'Red',
            'size' => 'M', 'base_image' => 'openimport/aa/bbbbbbbb/red.jpg', 'configurable:parent_sku' => 'TS'],
            ['name' => 'T-Shirt Basis Rot M']);
        $blue = $this->row(3, ['sku' => 'TS-BLUE-L', 'name' => 'T-Shirt Basic Blue L', 'price' => '21.90',
            'color' => 'Blue', 'size' => 'L', 'configurable:parent_sku' => 'TS'], ['name' => 'T-Shirt Basis Blau L']);
        $simple = $this->row(4, ['sku' => 'MUG', 'name' => 'Mug', 'price' => '5']);
        $this->batch($step, [$red, $simple]);
        $this->batch($step, [$blue]);

        self::assertSame('Not Visible Individually', $red->rows[0]['visibility']);
        self::assertSame('', $red->rows[0]['url_key'] ?? '', 'TS-RED-M exists: its URL key stays');
        self::assertSame('t-shirt-basic-blue-l-ts-blue-l', $blue->rows[0]['url_key'], 'a new variant gets name-sku');
        self::assertSame('', $simple->rows[0]['visibility'], 'other rows are untouched');
        self::assertArrayNotHasKey('url_key', $simple->rows[0]);

        $parents = $step->finish(static function (): void {
        });
        self::assertCount(1, $parents);
        $parent = $parents[0];
        self::assertSame(['TS', 2], [$parent->identifier, $parent->line]);
        self::assertSame('configurable', $parent->rows[0]['product_type']);
        self::assertSame('T-Shirt Basic', $parent->rows[0]['name']);
        self::assertSame('T-Shirt Basis', $parent->rows[1]['name']);
        self::assertSame('openimport/aa/bbbbbbbb/red.jpg', $parent->rows[0]['base_image'], 'pictures of a variant that passed');
        self::assertSame('Catalog, Search', $parent->rows[0]['visibility']);
        self::assertSame(
            'sku=TS-RED-M,color=Red,size=M|sku=TS-BLUE-L,color=Blue,size=L',
            $parent->rows[0]['configurable_variations']
        );
        self::assertSame('Configurable products: 1 built from 2 variants.', $step->summary());
    }

    public function testVariantsLeftOutByTheDeltaStillCountAndTheMappedNameWins(): void
    {
        $step = $this->step();
        $step->begin($this->profile(['configurable_child_visibility' => 'keep']), 'add_update', false);
        $step->columns(self::COLUMNS);
        $unchanged = $this->row(2, ['sku' => 'TS-RED-M', 'name' => 'Red', 'color' => 'Red', 'size' => 'M',
            'base_image' => 'https://cdn.example.com/red.jpg', 'visibility' => 'Catalog',
            'configurable:parent_sku' => 'TS', 'configurable:parent_name' => 'Shirt']);
        $changed = $this->row(3, ['sku' => 'TS-RED-L', 'name' => 'Red L', 'color' => 'Red', 'size' => 'L',
            'visibility' => 'Catalog', 'configurable:parent_sku' => 'TS']);
        // Only the changed variant reaches apply().
        $step->observe([$unchanged, $changed]);
        $step->apply([$changed], static function (): void {
        });

        self::assertSame('Catalog', $changed->rows[0]['visibility'], 'visibility as mapped');
        $parent = $step->finish(static function (): void {
        })[0];
        self::assertSame('Shirt', $parent->rows[0]['name']);
        self::assertSame('sku=TS-RED-M,color=Red,size=M|sku=TS-RED-L,color=Red,size=L', $parent->rows[0]['configurable_variations']);
        self::assertSame('', $parent->rows[0]['base_image'], 'the template passed the steps, it has no picture');
    }

    public function testATemplateThatDidNotPassTheStepsLosesItsPictures(): void
    {
        $step = $this->step();
        $step->begin($this->profile(), 'add_update', false);
        $step->columns(self::COLUMNS);
        $step->observe([$this->row(2, ['sku' => 'TS-RED-M', 'name' => 'Red', 'color' => 'Red', 'size' => 'M',
            'base_image' => 'https://cdn.example.com/red.jpg', 'configurable:parent_sku' => 'TS'])]);
        $parent = $step->finish(static function (): void {
        })[0];
        self::assertSame('', $parent->rows[0]['base_image'], 'core never downloads; an empty cell keeps the picture');
    }

    public function testBadVariantsFailAndAreLeftOutOfTheParent(): void
    {
        $step = $this->step();
        $step->begin($this->profile(), 'add_update', false);
        $step->columns(self::COLUMNS);
        $good = $this->row(2, ['sku' => 'A-1', 'name' => 'A 1', 'color' => 'Red', 'size' => 'M', 'configurable:parent_sku' => 'A']);
        $empty = $this->row(3, ['sku' => 'A-2', 'name' => 'A 2', 'color' => 'Red', 'size' => '', 'configurable:parent_sku' => 'A']);
        $twin = $this->row(4, ['sku' => 'A-3', 'name' => 'A 3', 'color' => 'red', 'size' => 'm', 'configurable:parent_sku' => 'A']);
        $self = $this->row(5, ['sku' => 'B', 'name' => 'B', 'color' => 'Red', 'size' => 'M', 'configurable:parent_sku' => 'b']);
        $parentAsVariant = $this->row(6, ['sku' => 'A', 'name' => 'A', 'color' => 'Blue', 'size' => 'M',
            'configurable:parent_sku' => 'Z']);
        $purple = $this->row(7, ['sku' => 'A-4', 'name' => 'A 4', 'color' => 'Purple', 'size' => 'M',
            'configurable:parent_sku' => 'A']);
        $this->batch($step, [$good, $empty, $twin, $self, $parentAsVariant, $purple]);

        self::assertFalse($good->isFailed());
        self::assertSame(['The variation attribute "size" is empty.', 'size'], [$empty->error(), $empty->errorColumn()]);
        self::assertSame('Another variant of A, A-1, has the same color, size.', $twin->error());
        self::assertSame('The parent SKU is the row\'s own SKU; a variant needs another parent.', $self->error());
        self::assertSame('This SKU is the parent of other rows; it cannot be a variant too.', $parentAsVariant->error());
        self::assertSame(
            ['"Purple" is not an option of color; add it first (attribute import or Stores › Attributes).', 'color'],
            [$purple->error(), $purple->errorColumn()]
        );
        $parents = $step->finish(static function (): void {
        });
        self::assertCount(1, $parents, 'B and Z have no valid variant');
        self::assertSame('sku=A-1,color=Red,size=M', $parents[0]->rows[0]['configurable_variations']);
        self::assertSame('Configurable products: 1 built from 1 variants. 5 variant(s) left out, see the errors.', $step->summary());
    }

    public function testTheParentsOwnRowIsTakenOver(): void
    {
        $step = $this->step();
        $step->begin($this->profile(), 'add_update', false);
        $step->columns(self::COLUMNS);
        $variant = $this->row(3, ['sku' => 'P-1', 'name' => 'Polo Red', 'color' => 'Red', 'size' => 'M',
            'configurable:parent_sku' => 'P']);
        $variant->rows[0]['price'] = '24.90';
        $own = $this->row(2, ['sku' => 'P', 'name' => 'Polo shirt', 'product_type' => 'Configurable',
            'visibility' => 'Search', 'base_image' => 'openimport/cc/dddddddd/polo.jpg']);
        $this->batch($step, [$own]);
        $this->batch($step, [$variant]);

        self::assertTrue($own->isAbsorbed(), 'sent once, at the end');
        self::assertFalse($own->isFailed());
        $parents = $step->finish(static function (): void {
        });
        self::assertCount(1, $parents);
        self::assertSame(['P', 2], [$parents[0]->identifier, $parents[0]->line]);
        self::assertSame('Polo shirt', $parents[0]->rows[0]['name']);
        self::assertSame('24.90', $parents[0]->rows[0]['price'], 'a parent row without a price takes its first variant\'s');
        self::assertSame('Search', $parents[0]->rows[0]['visibility']);
        self::assertSame('openimport/cc/dddddddd/polo.jpg', $parents[0]->rows[0]['base_image']);
        self::assertSame('configurable', $parents[0]->rows[0]['product_type']);
        self::assertSame('sku=P-1,color=Red,size=M', $parents[0]->rows[0]['configurable_variations']);
    }

    public function testAParentRowWithoutVariantsIsStillSent(): void
    {
        $step = $this->step();
        $step->begin($this->profile(), 'add_update', false);
        $step->columns(self::COLUMNS);
        $own = $this->row(2, ['sku' => 'LONELY', 'name' => 'Lonely', 'product_type' => 'configurable']);
        $this->batch($step, [$own]);
        $parents = $step->finish(static function (): void {
        });
        self::assertSame('LONELY', $parents[0]->identifier);
        self::assertSame('', $parents[0]->rows[0]['configurable_variations']);
    }

    public function testASimpleRowWithAParentsSkuStopsThatParent(): void
    {
        $step = $this->step();
        $step->begin($this->profile(), 'add_update', false);
        $step->columns(self::COLUMNS);
        $this->batch($step, [
            $this->row(2, ['sku' => 'C', 'name' => 'C']),
            $this->row(3, ['sku' => 'C-1', 'name' => 'C 1', 'color' => 'Red', 'size' => 'S', 'configurable:parent_sku' => 'C']),
        ]);
        self::assertSame([], $step->finish(static function (): void {
        }));
        self::assertStringContainsString(
            'Not built because the file has a simple product with the parent SKU: C.',
            $step->summary()
        );
    }

    public function testNoSummaryWithoutVariants(): void
    {
        $step = $this->step();
        $step->begin($this->profile(), 'add_update', false);
        $step->columns(self::COLUMNS);
        $this->batch($step, [$this->row(2, ['sku' => 'MUG', 'name' => 'Mug'])]);
        self::assertSame([], $step->finish(static function (): void {
        }));
        self::assertSame('', $step->summary());
    }
}
