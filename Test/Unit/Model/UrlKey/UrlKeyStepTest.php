<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\UrlKey;

use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Pipeline\Step\StepRecord;
use Iranimij\OpenImport\Model\UrlKey\UrlKeyPattern;
use Iranimij\OpenImport\Model\UrlKey\UrlKeyStep;
use Iranimij\OpenImport\Test\Unit\Model\Configurable\Fake\FakeUrlKey;
use Iranimij\OpenImport\Test\Unit\Model\ProfileStub;
use Iranimij\OpenImport\Test\Unit\Model\UrlKey\Fake\FakeUrlKeyOwners;
use PHPUnit\Framework\TestCase;

class UrlKeyStepTest extends TestCase
{
    /**
     * @param array<string, string> $owners
     * @return UrlKeyStep
     */
    private function step(array $owners = []): UrlKeyStep
    {
        return new UrlKeyStep(new UrlKeyPattern(), new FakeUrlKey(), new FakeUrlKeyOwners($owners));
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

    private function noTick(): \Closure
    {
        return static function (): void {
        };
    }

    public function testPatternFields(): void
    {
        $patterns = new UrlKeyPattern();
        self::assertSame(['name', 'sku'], $patterns->fields('{name}-{ sku }-{name}'));
        self::assertSame([], $patterns->fields('name-sku'));
        self::assertSame('Shirt-A-1', $patterns->fill('{name}-{sku}', ['name' => 'Shirt', 'sku' => 'A-1']));
        self::assertSame('Hemd-A-1', $patterns->fill('{name}-{sku}', ['name' => 'Hemd'], ['name' => 'Shirt', 'sku' => 'A-1']));
        self::assertSame('', $patterns->fill('{name}', ['name' => ' ']));
    }

    public function testWhenTheStepTakesPart(): void
    {
        $step = $this->step();
        self::assertFalse($step->begin($this->profile([]), 'add_update', false));
        self::assertTrue($step->begin($this->profile(['url_redirects' => '1']), 'add_update', false));
        self::assertTrue($step->begin($this->profile(['url_key_pattern' => '{name}']), 'add_update', false));
        self::assertFalse($step->begin($this->profile(['url_key_pattern' => '{name}'], 'customer'), 'add_update', false));
        self::assertFalse($step->begin($this->profile(['url_key_pattern' => '{name}']), 'delete', false));
    }

    public function testAPatternWithoutFieldsStopsTheRun(): void
    {
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage('names no field');
        $this->step()->begin($this->profile(['url_key_pattern' => 'name-sku']), 'add_update', false);
    }

    public function testColumns(): void
    {
        $step = $this->step();
        $step->begin($this->profile(['url_key_pattern' => '{name}-{sku}', 'url_redirects' => '1']), 'add_update', false);
        self::assertSame(['sku', 'name', 'url_key', 'save_rewrites_history'], $step->columns(['sku', 'name']));
        $this->expectException(PipelineException::class);
        $this->expectExceptionMessage('The URL key pattern uses color');
        $step->begin($this->profile(['url_key_pattern' => '{name}-{color}']), 'add_update', false);
        $step->columns(['sku', 'name']);
    }

    public function testKeysFromThePatternAndRedirects(): void
    {
        $step = $this->step(['shirt-b-1' => 'B-1', 'mug-m-1' => 'M-1']);
        $step->begin($this->profile(['url_key_pattern' => '{name}-{sku}', 'url_redirects' => '1']), 'add_update', false);
        $step->columns(['sku', 'name', 'url_key']);
        $a = new StepRecord(2, 'A-1', [
            ['sku' => 'A-1', 'name' => 'Shirt', 'url_key' => '', 'store_view_code' => ''],
            ['sku' => 'A-1', 'name' => 'Hemd', 'url_key' => '', 'store_view_code' => 'de'],
            ['sku' => 'A-1', 'name' => '', 'url_key' => '', 'store_view_code' => 'fr'],
        ]);
        $own = new StepRecord(3, 'A-2', [['sku' => 'A-2', 'name' => 'Shirt', 'url_key' => 'my-own-key']]);
        $taken = new StepRecord(4, 'B-1', [['sku' => 'B-1', 'name' => 'Shirt', 'url_key' => '']]);
        $clash = new StepRecord(5, 'X', [['sku' => 'M-1', 'name' => 'Mug', 'url_key' => '']]);
        $twice = new StepRecord(6, 'a-1', [['sku' => 'a-1', 'name' => 'Shirt', 'url_key' => '']]);
        $step->apply([$a, $own, $taken, $clash], $this->noTick());
        $step->apply([$twice], $this->noTick());

        self::assertSame('shirt-a-1', $a->rows[0]['url_key']);
        self::assertSame('hemd-a-1', $a->rows[1]['url_key'], 'a store view with its own name gets its own key');
        self::assertSame('', $a->rows[2]['url_key'], 'a store view without own values inherits');
        self::assertSame(['1', '1', '1'], array_column($a->rows, 'save_rewrites_history'));
        self::assertSame('my-own-key', $own->rows[0]['url_key'], 'a mapped key wins');
        self::assertFalse($taken->isFailed(), 'B-1 has that key itself');
        self::assertSame(['The URL key "mug-m-1" belongs to M-1 already.', 'url_key'], [$clash->error(), $clash->errorColumn()]);
        self::assertFalse($twice->isFailed(), 'the same SKU (any case) may get its key again');
        self::assertSame('URL keys made from the pattern: 3.', $step->summary());
    }

    public function testAKeyMadeTwiceInARunFails(): void
    {
        $step = $this->step();
        $step->begin($this->profile(['url_key_pattern' => '{name}']), 'add_update', false);
        $step->columns(['sku', 'name']);
        $first = new StepRecord(2, 'A', [['sku' => 'A', 'name' => 'Shirt']]);
        $second = new StepRecord(3, 'B', [['sku' => 'B', 'name' => 'Shirt']]);
        $step->apply([$first, $second], $this->noTick());
        self::assertFalse($first->isFailed());
        self::assertSame('The URL key "shirt" belongs to A already.', $second->error());
    }
}
