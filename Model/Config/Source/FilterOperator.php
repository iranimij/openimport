<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Row filter operators.
 */
class FilterOperator implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ([
            'equals' => 'is',
            'not_equals' => 'is not',
            'contains' => 'contains',
            'not_contains' => 'does not contain',
            'starts_with' => 'starts with',
            'ends_with' => 'ends with',
            'in' => 'is one of (comma-separated)',
            'not_in' => 'is none of (comma-separated)',
            'empty' => 'is empty',
            'not_empty' => 'is not empty',
            'greater_than' => 'is a number above',
            'less_than' => 'is a number below',
            'regex' => 'matches the pattern',
            'expression' => 'expression is true',
        ] as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => __($label)];
        }

        return $options;
    }
}
