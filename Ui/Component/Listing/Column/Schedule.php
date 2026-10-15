<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Ui\Component\Listing\Column;

use Iranimij\OpenImport\Model\Admin\ProfileFormMapper;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * The schedule in words: presets by name, other cron expressions as written, "Manual" without one.
 */
class Schedule extends Column
{
    /**
     * @param array<string, mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource)
    {
        if (!isset($dataSource['data']['items']) || !is_array($dataSource['data']['items'])) {
            return $dataSource;
        }
        // By reference into the array itself: a "?? []" expression would be a copy and the grid would keep raw values.
        foreach ($dataSource['data']['items'] as &$item) {
            $schedule = trim((string) ($item['schedule'] ?? ''));
            $label = ProfileFormMapper::SCHEDULE_PRESETS[$schedule] ?? null;
            $daily = ProfileFormMapper::dailyTime($schedule);
            $text = match (true) {
                $label !== null => (string) __($label),
                $daily !== null => (string) __('Every day at %1', $daily),
                default => $schedule,
            };
            if ($schedule !== '' && empty($item['is_active'])) {
                $text = (string) __('%1 (off)', $text);
            }
            $item['schedule'] = $text;
        }

        return $dataSource;
    }
}
