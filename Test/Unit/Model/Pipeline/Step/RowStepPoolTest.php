<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Pipeline\Step;

use Iranimij\OpenImport\Model\Pipeline\Step\RowStepPool;
use Iranimij\OpenImport\Model\Pipeline\Step\StepRecord;
use Iranimij\OpenImport\Test\Unit\Model\Pipeline\Fake\FakeRowStep;
use Iranimij\OpenImport\Test\Unit\Model\ProfileStub;
use PHPUnit\Framework\TestCase;

class RowStepPoolTest extends TestCase
{
    public function testOnlyStepsThatTakePartAreReturnedInOrder(): void
    {
        $first = new FakeRowStep();
        $off = new FakeRowStep(false);
        $last = new FakeRowStep();
        $pool = new RowStepPool(['first' => $first, 'off' => $off, 'last' => $last]);

        self::assertSame([$first, $last], $pool->begin(new ProfileStub([]), 'replace', true));
        self::assertSame('replace dry', $off->began, 'every step is asked');
        self::assertSame([], (new RowStepPool())->begin(new ProfileStub([]), 'add_update', false));
    }

    public function testStepsMustImplementTheInterface(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Row step "wrong" must implement RowStepInterface.');
        // @phpstan-ignore-next-line argument.type -- the wrong type is the point of the test
        new RowStepPool(['wrong' => new \stdClass()]);
    }

    public function testTheFirstFailureWins(): void
    {
        $record = new StepRecord(4, 'A-1', [['sku' => 'A-1']]);
        self::assertFalse($record->isFailed());
        $record->fail('First', 'base_image');
        $record->fail('Second', 'name');
        self::assertTrue($record->isFailed());
        self::assertSame(['First', 'base_image'], [$record->error(), $record->errorColumn()]);
        self::assertFalse($record->isIncomplete());
        $record->markIncomplete();
        self::assertTrue($record->isIncomplete());
    }
}
