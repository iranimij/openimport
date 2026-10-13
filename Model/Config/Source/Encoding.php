<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * CSV encodings.
 */
class Encoding implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ([
            'auto' => 'Detect',
            'UTF-8' => 'UTF-8',
            'Windows-1252' => 'Windows-1252 (Excel, Western Europe)',
            'ISO-8859-1' => 'ISO-8859-1',
            'ISO-8859-15' => 'ISO-8859-15',
        ] as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => __($label)];
        }

        return $options;
    }
}
