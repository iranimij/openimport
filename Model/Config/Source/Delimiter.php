<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * CSV delimiters.
 */
class Delimiter implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ([
            'auto' => 'Detect',
            ',' => 'Comma (,)',
            ';' => 'Semicolon (;)',
            '\\t' => 'Tab',
            '|' => 'Pipe (|)',
        ] as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => __($label)]
                // "Detect" reads the file: imports only.
                + ($value === 'auto' ? ['showWhen' => ['direction' => ['import']]] : []);
        }

        return $options;
    }
}
