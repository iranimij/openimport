<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * What happens to a source file after a successful run.
 */
class AfterSuccess implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ([
            'keep' => 'Keep it',
            'move' => 'Move it to the processed folder',
            'delete' => 'Delete it',
        ] as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => __($label)];
        }

        return $options;
    }
}
