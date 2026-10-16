<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Pipeline\Step;

/**
 * What a post-import step would change.
 */
class PostImportPlan
{
    /**
     * @param string $verb "disable", "delete", "set out of stock"
     * @param string $word the word a user types to confirm ("DISABLE")
     * @param string[] $identifiers the records it changes
     * @param int $total records in the step's scope (for the safety limit)
     */
    public function __construct(
        public readonly string $verb,
        public readonly string $word,
        public readonly array $identifiers,
        public readonly int $total
    ) {
    }

    public function count(): int
    {
        return count($this->identifiers);
    }
}
