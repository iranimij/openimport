<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Connection;

/**
 * The kinds of saved connection (di.xml: code => name). Each one is also a source type and an export destination.
 */
class ConnectionTypes
{
    /**
     * @param array<string, string> $types code => name, e.g. sftp => SFTP
     */
    public function __construct(
        private readonly array $types = []
    ) {
    }

    /**
     * @return string[]
     */
    public function codes(): array
    {
        return array_keys($this->types);
    }

    /**
     * @param string $code
     * @return bool
     */
    public function has(string $code): bool
    {
        return isset($this->types[$code]);
    }

    /**
     * @param string $code
     * @return string the name, or the code for an unknown type
     */
    public function label(string $code): string
    {
        return (string) ($this->types[$code] ?? $code);
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->types;
    }
}
