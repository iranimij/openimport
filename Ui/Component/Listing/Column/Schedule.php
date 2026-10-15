<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Ui\Component\Listing\Column;

use Iranimij\OpenImport\Model\Admin\ProfileFormMapper;
use Iranimij\OpenImport\Model\Schedule\DailyTime;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * The schedule in words: presets by name, other cron expressions as written, "Manual" without one.
 */
class Schedule extends Column
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param DailyTime $dailyTime
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly DailyTime $dailyTime,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

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
            $daily = $this->dailyTime->timeOf($schedule);
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
