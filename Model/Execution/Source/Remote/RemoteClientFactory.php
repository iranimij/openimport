<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Execution\Source\Remote;

use Magento\Framework\ObjectManagerInterface;

/**
 * A new client per connection (clients hold the connection). Protocols are registered in di.xml.
 */
class RemoteClientFactory
{
    /**
     * @param ObjectManagerInterface $objectManager
     * @param array<string, string> $clients protocol => class implementing RemoteClientInterface
     */
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly array $clients = []
    ) {
    }

    /**
     * @param string $protocol
     * @return RemoteClientInterface
     */
    public function create(string $protocol): RemoteClientInterface
    {
        $class = $this->clients[$protocol] ?? null;
        if ($class === null) {
            throw new \InvalidArgumentException(sprintf('No client for "%s".', $protocol));
        }
        $client = $this->objectManager->create($class);
        if (!$client instanceof RemoteClientInterface) {
            throw new \InvalidArgumentException(sprintf('%s is not a RemoteClientInterface.', $class));
        }

        return $client;
    }
}
