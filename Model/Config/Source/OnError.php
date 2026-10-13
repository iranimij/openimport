<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * What a run does when rows have errors.
 */
class OnError implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ([
            'stop' => 'Stop: import nothing',
            'skip' => 'Skip the rows with errors',
        ] as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => __($label)];
        }

        return $options;
    }
}
