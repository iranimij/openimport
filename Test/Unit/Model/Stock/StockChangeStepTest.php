<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Stock;

use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Pipeline\Step\StepRecord;
use Iranimij\OpenImport\Model\Stock\QuantityChange;
use Iranimij\OpenImport\Model\Stock\StockChangeStep;
use Iranimij\OpenImport\Test\Unit\Model\ProfileStub;
use Iranimij\OpenImport\Test\Unit\Model\Stock\Fake\FakeQuantityReader;
use PHPUnit\Framework\TestCase;

class StockChangeStepTest extends TestCase
{
    /**
     * @param string $entity
     * @param array<string, mixed> $data
     * @return ProfileStub
     */
    private function profile(string $entity = 'openimport_stock_price', array $data = []): ProfileStub
    {
        return new ProfileStub($data + ['entity' => $entity, 'import_options' => ['qty_mode' => 'add']]);
    }

    private function noTick(): \Closure
    {
        return static function (): void {
        };
    }

    public function testWhenTheStepTakesPart(): void
    {
        $step = new StockChangeStep(new FakeQuantityReader(), new QuantityChange());
        self::assertTrue($step->begin($this->profile(), 'only_update', false));
        self::assertTrue($step->begin($this->profile('catalog_product'), 'add_update', true));
        self::assertTrue($step->begin($this->profile('stock_sources'), 'add_update', false));
        self::assertFalse($step->begin($this->profile('customer'), 'add_update', false));
        self::assertFalse($step->begin($this->profile('catalog_product', ['import_options' => ['qty_mode' => 'set']]), 'add_update', false));
        self::assertFalse($step->begin($this->profile(), 'delete', false));
    }

    public function testSkipUnchangedRowsIsRefused(): void
    {
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage('"Skip unchanged rows" cannot be used with quantities as changes');
        (new StockChangeStep(new FakeQuantityReader(), new QuantityChange()))
            ->begin($this->profile('catalog_product', ['delta' => true]), 'add_update', false);
    }

    public function testTheQuantityColumnIsNeeded(): void
    {
        $step = new StockChangeStep(new FakeQuantityReader(), new QuantityChange());
        $step->begin($this->profile('stock_sources'), 'add_update', false);
        self::assertSame(['sku', 'source_code', 'quantity'], $step->columns(['sku', 'source_code', 'quantity']));
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage('Map the quantity (quantity) in step 4');
        $step->columns(['sku', 'source_code', 'qty']);
    }

    public function testChangesAddToTheCurrentQuantity(): void
    {
        $reader = new FakeQuantityReader(['a-1' => 10.0, 'a-2' => 2.0]);
        $step = new StockChangeStep($reader, new QuantityChange());
        $step->begin($this->profile(), 'add_update', false);
        $records = [
            new StepRecord(2, 'A-1', [['sku' => 'A-1', 'qty' => '+5']]),
            new StepRecord(3, 'A-2', [['sku' => 'A-2', 'qty' => '-3']]),
            new StepRecord(4, 'NEW', [['sku' => 'NEW', 'qty' => '4']]),
            new StepRecord(5, 'A-3', [['sku' => 'A-3', 'qty' => '']]),
            new StepRecord(6, 'A-4', [['sku' => 'A-4', 'qty' => 'lots']]),
        ];
        $step->apply($records, $this->noTick());

        self::assertSame(['15', '-1', '4', ''], array_map(
            static fn (StepRecord $record): string => $record->rows[0]['qty'],
            array_slice($records, 0, 4)
        ));
        self::assertSame(['"lots" is not a change of quantity (+5, -3 or 4).', 'qty'], [$records[4]->error(), $records[4]->errorColumn()]);

        // The same SKU later in the file adds to what this run computed.
        $later = new StepRecord(7, 'a-1', [['sku' => 'a-1', 'qty' => '+2,5']]);
        $step->apply([$later], $this->noTick());
        self::assertSame('17.5', $later->rows[0]['qty']);
        self::assertSame('Quantities changed by the file: 4.', $step->summary());
    }

    public function testSourcesAreKeptApart(): void
    {
        $step = new StockChangeStep(
            new FakeQuantityReader([], ['a-1|default' => 3.0, 'a-1|berlin' => 7.0]),
            new QuantityChange()
        );
        $step->begin($this->profile('stock_sources'), 'add_update', false);
        $records = [
            new StepRecord(2, 'A-1|default', [['sku' => 'A-1', 'source_code' => 'default', 'quantity' => '+1']]),
            new StepRecord(3, 'A-1|berlin', [['sku' => 'A-1', 'source_code' => 'berlin', 'quantity' => '-2']]),
            new StepRecord(4, 'A-1|hamburg', [['sku' => 'A-1', 'source_code' => 'hamburg', 'quantity' => '6']]),
        ];
        $step->apply($records, $this->noTick());
        self::assertSame(['4', '5', '6'], array_map(static fn (StepRecord $r): string => $r->rows[0]['quantity'], $records));
    }

    public function testNoSummaryWithoutChanges(): void
    {
        $step = new StockChangeStep(new FakeQuantityReader(), new QuantityChange());
        $step->begin($this->profile(), 'add_update', false);
        $step->apply([new StepRecord(2, 'A', [['sku' => 'A', 'qty' => '']])], $this->noTick());
        self::assertSame('', $step->summary());
    }
}
