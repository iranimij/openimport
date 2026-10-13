<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Run statuses.
 */
class RunStatus implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ([
            'queued' => 'Queued',
            'running' => 'Running',
            'success' => 'Success',
            'partial' => 'Partial',
            'failed' => 'Failed',
            'needs_confirmation' => 'Needs confirmation',
            'cancelled' => 'Cancelled',
        ] as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => __($label)];
        }

        return $options;
    }
}
