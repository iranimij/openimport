<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * What started a run.
 */
class RunTrigger implements OptionSourceInterface
{
    private const LABELS = [
        'manual' => 'Admin',
        'schedule' => 'Schedule',
        'cli' => 'Command line',
        'api' => 'API',
        'event' => 'Order placed',
        'chain' => 'Job chain',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach (self::LABELS as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => __($label)];
        }

        return $options;
    }

    /**
     * "Admin · openimport", "Schedule", "Command line · deploy" …: how a run was started and by whom.
     *
     * @param string $trigger
     * @param string|null $requestedBy
     * @return string
     */
    public function describe(string $trigger, ?string $requestedBy): string
    {
        $labels = array_column($this->toOptionArray(), 'label', 'value');
        $label = isset($labels[$trigger]) ? (string) $labels[$trigger] : $trigger;
        $who = trim((string) $requestedBy);

        return $who === '' || $who === $trigger ? $label : $label . ' · ' . $who;
    }
}
