<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Missing;

use Iranimij\OpenImport\Model\Bridge\ImporterPool;
use Iranimij\OpenImport\Model\Bridge\RowError;
use Iranimij\OpenImport\Model\Missing\MissingProductsStep;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Pipeline\Step\StepRecord;
use Iranimij\OpenImport\Test\Unit\Model\Missing\Fake\FakeMissingScope;
use Iranimij\OpenImport\Test\Unit\Model\Pipeline\Fake\FakeCoreImporter;
use Iranimij\OpenImport\Test\Unit\Model\ProfileStub;
use PHPUnit\Framework\TestCase;

class MissingProductsStepTest extends TestCase
{
    private ?FakeCoreImporter $core = null;

    private ?FakeMissingScope $scope = null;

    private function step(): MissingProductsStep
    {
        $this->core = new FakeCoreImporter();
        $this->scope = new FakeMissingScope(['a' => [true, true], 'B' => [true, true], 'c-parent' => [true, true]]);

        return new MissingProductsStep($this->scope, new ImporterPool($this->core));
    }

    /**
     * @param array<string, string> $options
     * @param string $entity
     * @return ProfileStub
     */
    private function profile(array $options, string $entity = 'catalog_product'): ProfileStub
    {
        return new ProfileStub(['entity' => $entity, 'import_options' => $options]);
    }

    public function testWhenTheStepTakesPart(): void
    {
        $step = $this->step();
        self::assertFalse($step->begin($this->profile([]), 'add_update', false));
        self::assertFalse($step->begin($this->profile(['missing_action' => 'archive']), 'add_update', false));
        self::assertFalse($step->begin($this->profile(['missing_action' => 'disable'], 'customer'), 'add_update', false));
        self::assertFalse($step->begin($this->profile(['missing_action' => 'disable']), 'delete', false));
        self::assertFalse($step->begin($this->profile(['missing_action' => 'disable']), 'only_add', false));
        self::assertTrue($step->begin($this->profile(['missing_action' => 'disable']), 'only_update', false));
        self::assertSame('Products missing from the file', $step->label());
    }

    public function testAWrongScopeStopsTheRun(): void
    {
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage('there is no website "eu"');
        $this->step()->begin(
            $this->profile(['missing_action' => 'disable', 'missing_scope' => 'website', 'missing_website' => 'eu']),
            'add_update',
            false
        );
    }

    public function testEverySkuTheFileNamesIsKept(): void
    {
        $step = $this->step();
        $step->begin($this->profile(['missing_action' => 'disable', 'missing_scope' => 'website',
            'missing_website' => 'base']), 'add_update', false);
        $step->observe([new StepRecord(2, 'A', [['sku' => 'A']])]);
        $step->observeIdentifiers(['C-PARENT']);

        $plan = $step->plan();
        self::assertNotNull($plan);
        self::assertSame(['B'], $plan->identifiers, 'SKUs compare without case');
        self::assertSame([3, 'disable', 'DISABLE'], [$plan->total, $plan->verb, $plan->word]);
        self::assertSame(['type' => 'website', 'website' => 'base', 'attribute' => '', 'value' => ''], $this->scope->asked[0][0]);
        self::assertSame('enabled', $this->scope->asked[0][1]);

        $step->observeIdentifiers(['b']);
        self::assertNull($step->plan(), 'nothing missing');
    }

    public function testMagentoRefusingTheChange(): void
    {
        $step = $this->step();
        $step->begin($this->profile(['missing_action' => 'out_of_stock']), 'add_update', false);
        $plan = $step->plan();
        self::assertNotNull($plan);
        self::assertSame(['set out of stock', 'OUT OF STOCK'], [$plan->verb, $plan->word]);
        $this->core->valid = false;
        $this->core->errors = [new RowError(0, 'locked', 'Stock is locked', null, false)];

        $outcome = $step->execute($plan, false);
        self::assertFalse($outcome->ok);
        self::assertSame('Products missing from the file: Magento refused the change. Stock is locked', $outcome->message);
        self::assertSame(0, $outcome->updated);
    }

    public function testManyProductsAreNamedShortly(): void
    {
        $this->core = new FakeCoreImporter();
        $shop = [];
        for ($i = 1; $i <= 14; $i++) {
            $shop[sprintf('P-%02d', $i)] = [true, true];
        }
        $step = new MissingProductsStep(new FakeMissingScope($shop), new ImporterPool($this->core));
        $step->begin($this->profile(['missing_action' => 'disable']), 'add_update', false);
        $plan = $step->plan();
        self::assertNotNull($plan);
        $outcome = $step->execute($plan, true);
        self::assertSame(
            'Products missing from the file: would disable 14 (P-01, P-02, P-03, P-04, P-05, P-06, P-07, P-08, P-09, P-10 and 4 more).',
            $outcome->message
        );
        self::assertSame(14, $outcome->updated);
        self::assertTrue($this->core->lastRequest()->dryRun);
    }
}
