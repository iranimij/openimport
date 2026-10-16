<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Config\Source;

use Iranimij\OpenImport\Model\Connection\ConnectionTypes;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Kinds of saved connection.
 */
class ConnectionType implements OptionSourceInterface
{
    /**
     * @param ConnectionTypes $types
     */
    public function __construct(
        private readonly ConnectionTypes $types
    ) {
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->types->all() as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => $label];
        }

        return $options;
    }
}
