<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Schedule;

/**
 * "Every day at a time" schedules: a cron expression "M H * * *" and its time "HH:MM".
 */
class DailyTime
{
    /**
     * @param string $schedule
     * @return string|null "HH:MM" when the schedule runs once every day, null otherwise
     */
    public function timeOf(string $schedule): ?string
    {
        if (!preg_match('/^(\d{1,2}) (\d{1,2}) \* \* \*$/', trim($schedule), $match)
            || (int) $match[1] > 59 || (int) $match[2] > 23
        ) {
            return null;
        }

        return sprintf('%02d:%02d', (int) $match[2], (int) $match[1]);
    }

    /**
     * @param string $time "HH:MM" (or "H.MM", "HHMM")
     * @return string the cron expression; a time that cannot be read is passed on, so the validator names it
     */
    public function cronOf(string $time): string
    {
        if (!preg_match('/^(\d{1,2})[:.h]?(\d{2})$/', trim($time), $match)) {
            return trim($time) . ' * * *';
        }

        return sprintf('%d %d * * *', (int) $match[2], (int) $match[1]);
    }
}
