<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * The entities for grid filters: each entity once, by its plain name.
 */
class EntityFilter implements OptionSourceInterface
{
    /**
     * @param Entity $entities
     */
    public function __construct(
        private readonly Entity $entities
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->entities->toOptionArray() as $option) {
            $options[(string) $option['value']] ??= ['value' => (string) $option['value'], 'label' => $option['label']];
        }

        return array_values($options);
    }
}
