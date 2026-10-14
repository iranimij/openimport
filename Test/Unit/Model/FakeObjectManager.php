<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model;

use Magento\Framework\ObjectManagerInterface;

/**
 * Creates objects from a map of class => factory closure (or a ready instance), for factories under unit test.
 */
class FakeObjectManager implements ObjectManagerInterface
{
    /**
     * @param array<string, mixed> $instances class => instance or closure(array $arguments)
     */
    public function __construct(private array $instances = [])
    {
    }

    /**
     * @inheritDoc
     */
    public function create($type, array $arguments = [])
    {
        $entry = $this->instances[$type] ?? null;
        if ($entry instanceof \Closure) {
            return $entry($arguments);
        }
        if ($entry !== null) {
            return $entry;
        }

        return new $type(...array_values($arguments));
    }

    /**
     * @inheritDoc
     */
    public function get($type)
    {
        return $this->create($type);
    }

    /**
     * @inheritDoc
     */
    public function configure(array $configuration)
    {
    }
}
