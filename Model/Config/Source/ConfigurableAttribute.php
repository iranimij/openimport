<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Config\Source;

use Iranimij\OpenImport\Model\Configurable\ConfigurableAttributes;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Product attributes that can make variants (global dropdowns the shop defined), "Label (code)".
 */
class ConfigurableAttribute implements OptionSourceInterface
{
    /**
     * @param ConfigurableAttributes $attributes
     */
    public function __construct(
        private readonly ConfigurableAttributes $attributes
    ) {
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->attributes->eligible() as $code => $label) {
            $options[] = ['value' => $code, 'label' => $label === $code ? $code : sprintf('%s (%s)', $label, $code)];
        }

        return $options;
    }
}
