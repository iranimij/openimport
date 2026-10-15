<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Schedule;

use Iranimij\OpenImport\Model\Schedule\CronExpression;
use PHPUnit\Framework\TestCase;

class CronExpressionTest extends TestCase
{
    private function berlin(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('Europe/Berlin'));
    }

    /**
     * @param string[] $expected
     * @param \DateTimeImmutable[] $times
     */
    private function assertTimes(array $expected, array $times): void
    {
        self::assertSame($expected, array_map(static fn (\DateTimeImmutable $time): string => $time->format('Y-m-d H:i D'), $times));
    }

    public function testNightly(): void
    {
        $cron = new CronExpression('0 2 * * *');

        $this->assertTimes(
            ['2026-10-14 02:00 Wed', '2026-10-15 02:00 Thu', '2026-10-16 02:00 Fri'],
            $cron->next($this->berlin('2026-10-13 17:45'), 3)
        );
        self::assertTrue($cron->matches($this->berlin('2026-10-13 02:00:59')));
        self::assertFalse($cron->matches($this->berlin('2026-10-13 02:01')));
    }

    public function testWeekdaysAtSix(): void
    {
        $this->assertTimes(
            ['2026-10-16 06:00 Fri', '2026-10-19 06:00 Mon'],
            (new CronExpression('0 6 * * mon-FRI'))->next($this->berlin('2026-10-15 06:00'), 2)
        );
    }

    public function testStepsListsAndRanges(): void
    {
        $this->assertTimes(
            ['2026-10-13 08:00 Tue', '2026-10-13 08:15 Tue', '2026-10-13 08:30 Tue', '2026-10-13 08:45 Tue', '2026-10-13 10:00 Tue'],
            (new CronExpression('*/15 8-18/2 * * *'))->next($this->berlin('2026-10-13 07:59'), 5)
        );
        $this->assertTimes(
            ['2026-11-01 00:05 Sun', '2026-11-15 00:05 Sun', '2027-01-01 00:05 Fri'],
            (new CronExpression('5 0 1,15 jan,NOV *'))->next($this->berlin('2026-10-13 00:00'), 3)
        );
        $this->assertTimes(
            ['2026-10-13 00:30 Tue', '2026-10-13 00:50 Tue'],
            (new CronExpression('30/20 0 * * *'))->next($this->berlin('2026-10-13 00:00'), 2)
        );
    }

    public function testDayOfMonthOrDayOfWeek(): void
    {
        // Either the 1st or a Sunday (Vixie cron), and 7 is Sunday too.
        $this->assertTimes(
            ['2026-10-18 12:00 Sun', '2026-10-25 12:00 Sun', '2026-11-01 12:00 Sun', '2026-11-08 12:00 Sun'],
            (new CronExpression('0 12 1 * 7'))->next($this->berlin('2026-10-13 00:00'), 4)
        );
        $this->assertTimes(
            ['2026-11-01 12:00 Sun', '2026-12-01 12:00 Tue'],
            (new CronExpression('0 12 1 * ?'))->next($this->berlin('2026-10-13 00:00'), 2)
        );
    }

    public function testLeapDay(): void
    {
        $this->assertTimes(
            ['2028-02-29 00:00 Tue'],
            (new CronExpression('0 0 29 2 *'))->next($this->berlin('2026-10-13 00:00'))
        );
    }

    public function testImpossibleDateFindsNothing(): void
    {
        self::assertSame([], (new CronExpression('0 0 31 2 *'))->next($this->berlin('2026-10-13 00:00')));
    }

    public function testExpressionIsNormalized(): void
    {
        self::assertSame('0 2 * * *', (new CronExpression("  0  2 *\t* * "))->expression());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidExpressions(): array
    {
        return [
            'too few fields' => ['0 2 * *', 'needs 5 fields'],
            'empty' => ['', 'has 0'],
            'bad minute' => ['60 * * * *', 'The minute "60" is outside 0-59'],
            'bad hour' => ['0 24 * * *', 'The hour "24" is outside 0-23'],
            'bad month name' => ['0 0 * FOO *', 'The month "FOO" is outside 1-12'],
            'day name in month' => ['0 0 * MON *', 'The month "MON" is outside'],
            'zero step' => ['*/0 * * * *', 'step must be at least 1'],
            'backwards' => ['0 5-1 * * *', 'runs backwards'],
            'garbage' => ['0 0 * * @', 'The day of week field "@" is not valid'],
            'every wrong field is named' => ['61 25 * * *', 'The minute "61" is outside 0-59. The hour "25" is outside 0-23.'],
        ];
    }

    /**
     * @dataProvider invalidExpressions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidExpressions')]
    public function testInvalidExpressions(string $expression, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new CronExpression($expression);
    }
}
