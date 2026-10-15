<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;

/**
 * A run count that only means something in one direction (config "direction"): empty for runs of the other one, so
 * an export does not show "0 new, 0 updated" and an import no "0 exported".
 */
class DirectionCount extends Column
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
        $direction = (string) ($this->getData('config/direction') ?: 'import');
        $name = (string) $this->getData('name');
        // By reference into the array itself (a "?? []" expression would be a copy).
        foreach ($dataSource['data']['items'] as &$item) {
            if (($item['direction'] ?? 'import') !== $direction) {
                $item[$name] = '';
            }
        }

        return $dataSource;
    }
}
