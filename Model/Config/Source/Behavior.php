<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Config\Source;

use Iranimij\OpenImport\Model\Entity\EntityRegistry;
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
            $option = ['value' => (string) $value, 'label' => __($label)];
            // The form offers a behaviour only for the entities that can do it.
            $notFor = match ($value) {
                'replace' => [...EntityRegistry::NO_REPLACE, EntityRegistry::STOCK_PRICE],
                'only_add', 'delete' => [EntityRegistry::STOCK_PRICE],
                default => [],
            };
            if ($notFor !== []) {
                $option['showWhen'] = ['entity' => ['not' => $notFor]];
            }
            $options[] = $option;
        }

        return $options;
    }
}
