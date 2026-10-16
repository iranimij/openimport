<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Config\Source;

use Iranimij\OpenImport\Api\ConnectionRepositoryInterface;
use Iranimij\OpenImport\Model\Connection\ConnectionTypes;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * "Login" select of a profile's source or destination: enter it here, or a saved connection of the chosen type.
 * Each connection is offered only when the source type (or destination) is its type.
 */
class ConnectionOptions implements OptionSourceInterface
{
    /**
     * @param ConnectionRepositoryInterface $connections
     * @param ConnectionTypes $types
     * @param string $typePath form path of the type the connection must match (source_type, export_options.destination)
     */
    public function __construct(
        private readonly ConnectionRepositoryInterface $connections,
        private readonly ConnectionTypes $types,
        private readonly string $typePath = 'source_type'
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        // Only kinds a profile can hold the login of itself offer "enter it here".
        $options = [[
            'value' => '',
            'label' => __('Enter the login here'),
            'showWhen' => [$this->typePath => ['not' => $this->types->connectionOnly()]],
        ]];
        foreach ($this->connections->getAll() as $connection) {
            $options[] = [
                'value' => (string) $connection->getConnectionId(),
                'label' => $connection->getName(),
                'showWhen' => [$this->typePath => [$connection->getType()]],
            ];
        }

        return $options;
    }
}
