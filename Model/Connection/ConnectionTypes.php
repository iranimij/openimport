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
     * @param string[] $inline the kinds whose login a profile can also hold itself (SFTP, FTP); the others need a
     *     saved connection
     */
    public function __construct(
        private readonly array $types = [],
        private readonly array $inline = []
    ) {
    }

    /**
     * @param string $code
     * @return bool whether a profile can hold this kind of login itself
     */
    public function allowsInline(string $code): bool
    {
        return in_array($code, $this->inline, true);
    }

    /**
     * @return string[] the kinds that need a saved connection
     */
    public function connectionOnly(): array
    {
        return array_values(array_diff($this->codes(), $this->inline));
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
