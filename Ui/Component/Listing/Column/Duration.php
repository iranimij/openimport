<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Run time from start to finish ("2 min 05 s").
 */
class Duration extends Column
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
            $started = isset($item['started_at']) ? strtotime((string) $item['started_at'] . ' UTC') : false;
            $finished = isset($item['finished_at']) ? strtotime((string) $item['finished_at'] . ' UTC') : false;
            if ($started === false || $finished === false || $finished < $started) {
                $item['duration'] = '';
                continue;
            }
            $seconds = $finished - $started;
            $item['duration'] = $seconds >= 60
                ? sprintf('%d min %02d s', intdiv($seconds, 60), $seconds % 60)
                : sprintf('%d s', $seconds);
        }

        return $dataSource;
    }
}
