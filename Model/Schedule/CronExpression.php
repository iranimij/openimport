<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Schedule;

/**
 * A standard 5-field cron expression: minute hour day-of-month month day-of-week.
 *
 * Fields accept *, numbers, ranges (1-5), steps (*\/15, 8-18/2), lists (1,15,30), month names (JAN-DEC) and day
 * names (SUN-SAT; 0 and 7 are Sunday). When both day fields are restricted, a day matches when either matches
 * (as in Vixie cron).
 */
class CronExpression
{
    private const FIELDS = [
        ['minute', 0, 59],
        ['hour', 0, 23],
        ['day of month', 1, 31],
        ['month', 1, 12],
        ['day of week', 0, 7],
    ];
    private const MONTHS = ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7,
        'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12];
    private const DAYS = ['SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6];

    /**
     * @var array<int, array<int, true>> allowed values per field
     */
    private array $sets = [];

    private bool $dayOfMonthRestricted;

    private bool $dayOfWeekRestricted;

    /**
     * @param string $expression
     * @throws \InvalidArgumentException explaining the problem
     */
    public function __construct(private readonly string $expression)
    {
        $parts = preg_split('/\s+/', trim($expression)) ?: [];
        if (count($parts) !== 5) {
            throw new \InvalidArgumentException(sprintf(
                'A schedule needs 5 fields (minute hour day month weekday), "%s" has %d.',
                trim($expression),
                count(array_filter($parts, static fn (string $part): bool => $part !== ''))
            ));
        }
        $problems = [];
        foreach ($parts as $index => $part) {
            try {
                $this->sets[$index] = $this->parseField(strtoupper($part), $index);
            } catch (\InvalidArgumentException $exception) {
                $problems[] = $exception->getMessage();
            }
        }
        if ($problems !== []) {
            // Name every wrong field at once, not one per save.
            throw new \InvalidArgumentException(implode(' ', $problems));
        }
        if (isset($this->sets[4][7])) {
            $this->sets[4][0] = true;
            unset($this->sets[4][7]);
        }
        $this->dayOfMonthRestricted = $parts[2] !== '*' && $parts[2] !== '?';
        $this->dayOfWeekRestricted = $parts[4] !== '*' && $parts[4] !== '?';
    }

    public function expression(): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $this->expression));
    }

    /**
     * Does the minute of this time match (seconds are ignored)?
     *
     * @param \DateTimeInterface $time in the time zone the schedule is meant for
     * @return bool
     */
    public function matches(\DateTimeInterface $time): bool
    {
        if (!isset($this->sets[0][(int) $time->format('i')], $this->sets[1][(int) $time->format('G')],
            $this->sets[3][(int) $time->format('n')])) {
            return false;
        }
        $dayOfMonth = isset($this->sets[2][(int) $time->format('j')]);
        $dayOfWeek = isset($this->sets[4][(int) $time->format('w')]);
        if ($this->dayOfMonthRestricted && $this->dayOfWeekRestricted) {
            return $dayOfMonth || $dayOfWeek;
        }

        return $dayOfMonth && $dayOfWeek;
    }

    /**
     * The next matching minutes after a time.
     *
     * @param \DateTimeImmutable $after
     * @param int $count
     * @return \DateTimeImmutable[]
     */
    public function next(\DateTimeImmutable $after, int $count = 1): array
    {
        $result = [];
        $time = $after->setTime((int) $after->format('G'), (int) $after->format('i'))->modify('+1 minute');
        // Search at most a little over four years minute-free: jump by day when the day cannot match.
        $limit = $after->modify('+5 years');
        while (count($result) < $count && $time < $limit) {
            if (!$this->dayMatches($time)) {
                $time = $time->setTime(0, 0)->modify('+1 day');
                continue;
            }
            if (!isset($this->sets[1][(int) $time->format('G')])) {
                $time = $time->setTime((int) $time->format('G'), 0)->modify('+1 hour');
                continue;
            }
            if ($this->matches($time)) {
                $result[] = $time;
            }
            $time = $time->modify('+1 minute');
        }

        return $result;
    }

    private function dayMatches(\DateTimeImmutable $time): bool
    {
        if (!isset($this->sets[3][(int) $time->format('n')])) {
            return false;
        }
        $dayOfMonth = isset($this->sets[2][(int) $time->format('j')]);
        $dayOfWeek = isset($this->sets[4][(int) $time->format('w')]);

        return $this->dayOfMonthRestricted && $this->dayOfWeekRestricted
            ? $dayOfMonth || $dayOfWeek
            : $dayOfMonth && $dayOfWeek;
    }

    /**
     * @param string $field
     * @param int $index
     * @return array<int, true>
     */
    private function parseField(string $field, int $index): array
    {
        [$name, $minimum, $maximum] = self::FIELDS[$index];
        $values = [];
        foreach (explode(',', $field) as $item) {
            if (preg_match('/^(\*|\?|[A-Z0-9]+(?:-[A-Z0-9]+)?)(?:\/(\d+))?$/', $item, $match) !== 1) {
                throw new \InvalidArgumentException(sprintf('The %s field "%s" is not valid.', $name, $field));
            }
            $step = isset($match[2]) ? (int) $match[2] : 1;
            if ($step < 1) {
                throw new \InvalidArgumentException(sprintf('The %s step must be at least 1.', $name));
            }
            if ($match[1] === '*' || $match[1] === '?') {
                [$from, $to] = [$minimum, $index === 4 ? 6 : $maximum];
            } else {
                $range = explode('-', $match[1]);
                $from = $this->value($range[0], $index, $name, $minimum, $maximum);
                $to = isset($range[1])
                    ? $this->value($range[1], $index, $name, $minimum, $maximum)
                    : ($step > 1 ? $maximum : $from);
                if ($from > $to) {
                    throw new \InvalidArgumentException(sprintf('The %s range "%s" runs backwards.', $name, $match[1]));
                }
            }
            for ($value = $from; $value <= $to; $value += $step) {
                $values[$value] = true;
            }
        }

        return $values;
    }

    private function value(string $text, int $index, string $name, int $minimum, int $maximum): int
    {
        $value = match (true) {
            ctype_digit($text) => (int) $text,
            $index === 3 && isset(self::MONTHS[$text]) => self::MONTHS[$text],
            $index === 4 && isset(self::DAYS[$text]) => self::DAYS[$text],
            default => null,
        };
        if ($value === null || $value < $minimum || $value > $maximum) {
            throw new \InvalidArgumentException(sprintf(
                'The %s "%s" is outside %d-%d.',
                $name,
                $text,
                $minimum,
                $maximum
            ));
        }

        return $value;
    }
}
