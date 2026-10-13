<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * What a run does with the records of the file.
 */
class Behavior implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ([
            'add_update' => 'Add new and update existing',
            'only_add' => 'Only add new records',
            'only_update' => 'Only update existing records',
            'replace' => 'Replace existing records',
            'delete' => 'Delete the records of the file',
        ] as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => __($label)];
        }

        return $options;
    }
}
