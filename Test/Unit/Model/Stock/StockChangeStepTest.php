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
        // Plain quantities: the step only checks decimals.
        self::assertTrue($step->begin($this->profile('catalog_product', ['import_options' => ['qty_mode' => 'set']]), 'add_update', false));
        self::assertSame(['sku', 'price'], $step->columns(['sku', 'price']), 'no quantity column needed then');
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

    /**
     * @param StockChangeStep $step
     * @param string $column
     * @return array<string, string> identifier => quantity sent at the end
     */
    private function sent(StockChangeStep $step, string $column = 'qty'): array
    {
        $sent = [];
        foreach ($step->finish($this->noTick()) as $record) {
            $sent[$record->identifier] = $record->rows[0][$column];
        }

        return $sent;
    }

    public function testChangesAddToTheCurrentQuantity(): void
    {
        $reader = new FakeQuantityReader(['a-1' => 10.0, 'a-2' => 2.0], [], ['a-1' => true]);
        $step = new StockChangeStep($reader, new QuantityChange());
        $step->begin($this->profile(), 'add_update', false);
        $records = [
            new StepRecord(2, 'A-1', [['sku' => 'A-1', 'qty' => '+5', 'price' => '9']]),
            new StepRecord(3, 'A-2', [['sku' => 'A-2', 'qty' => '-3']]),
            new StepRecord(4, 'NEW', [['sku' => 'NEW', 'qty' => '4']]),
            new StepRecord(5, 'A-3', [['sku' => 'A-3', 'qty' => '']]),
            new StepRecord(6, 'A-4', [['sku' => 'A-4', 'qty' => 'lots']]),
        ];
        $step->apply($records, $this->noTick());

        self::assertSame([true, true, true, false, false], array_map(
            static fn (StepRecord $record): bool => $record->isAbsorbed(),
            $records
        ), 'changes are sent at the end; an empty quantity goes as it is');
        self::assertSame(['"lots" is not a change of quantity (+5, -3 or 4).', 'qty'], [$records[4]->error(), $records[4]->errorColumn()]);

        // The same SKU later in the file adds to what this run computed; Magento gets one row per SKU.
        $later = new StepRecord(7, 'a-1', [['sku' => 'a-1', 'qty' => '+2,5', 'price' => '11']]);
        $step->apply([$later], $this->noTick());
        $finished = $step->finish($this->noTick());
        self::assertSame(['a-1' => '17.5', 'A-2' => '-1', 'NEW' => '4'], array_combine(
            array_map(static fn (StepRecord $record): string => $record->identifier, $finished),
            array_map(static fn (StepRecord $record): string => $record->rows[0]['qty'], $finished)
        ));
        self::assertSame('11', $finished[0]->rows[0]['price'], 'the latest row\'s other values');
        self::assertSame(7, $finished[0]->line);
        self::assertSame('Quantities changed by the file: 4.', $step->summary());
    }

    public function testSourcesAreKeptApart(): void
    {
        $step = new StockChangeStep(
            new FakeQuantityReader([], ['a-1|default' => 3.0, 'a-1|berlin' => 7.0]),
            new QuantityChange()
        );
        $step->begin($this->profile('stock_sources'), 'add_update', false);
        $step->apply([
            new StepRecord(2, 'A-1|default', [['sku' => 'A-1', 'source_code' => 'default', 'quantity' => '+1']]),
            new StepRecord(3, 'A-1|berlin', [['sku' => 'A-1', 'source_code' => 'berlin', 'quantity' => '-2']]),
            new StepRecord(4, 'A-1|hamburg', [['sku' => 'A-1', 'source_code' => 'hamburg', 'quantity' => '6']]),
        ], $this->noTick());
        self::assertSame(['A-1|default' => '4', 'A-1|berlin' => '5', 'A-1|hamburg' => '6'], $this->sent($step, 'quantity'));
    }

    public function testNoSummaryWithoutChanges(): void
    {
        $step = new StockChangeStep(new FakeQuantityReader(), new QuantityChange());
        $step->begin($this->profile(), 'add_update', false);
        $step->apply([new StepRecord(2, 'A', [['sku' => 'A', 'qty' => '']])], $this->noTick());
        self::assertSame('', $step->summary());
    }

    public function testDecimalsOnlyForProductsThatUseThem(): void
    {
        $reader = new FakeQuantityReader(['whole' => 12.0, 'kg' => 1.0], [], ['whole' => false, 'kg' => true]);
        foreach (['add' => ['+1.5', '+2'], 'set' => ['13.5', '14.0000']] as $mode => [$fraction, $whole]) {
            $step = new StockChangeStep($reader, new QuantityChange());
            $step->begin($this->profile('openimport_stock_price', ['import_options' => ['qty_mode' => $mode]]), 'add_update', false);
            $records = [
                new StepRecord(2, 'WHOLE', [['sku' => 'WHOLE', 'qty' => $fraction]]),
                new StepRecord(3, 'KG', [['sku' => 'KG', 'qty' => $fraction]]),
                new StepRecord(4, 'NEW', [['sku' => 'NEW', 'qty' => $fraction, 'is_qty_decimal' => '1']]),
                new StepRecord(5, 'WHOLE2', [['sku' => 'WHOLE', 'qty' => $whole]]),
            ];
            $step->apply($records, $this->noTick());
            self::assertStringContainsString('WHOLE is sold in whole pieces: "' . $fraction . '" has decimals', (string) $records[0]->error(), $mode);
            self::assertEmpty($records[1]->error(), $mode);
            self::assertEmpty($records[2]->error(), $mode . ': the row itself switches decimals on');
            self::assertEmpty($records[3]->error(), $mode);
        }
    }
}
