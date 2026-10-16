<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Pipeline\Step;

/**
 * What a post-import step did.
 */
class PostImportOutcome
{
    /**
     * @param string $message one line for the run message
     * @param int $updated
     * @param int $deleted
     * @param bool $ok false when Magento refused it (the run becomes partial)
     */
    public function __construct(
        public readonly string $message,
        public readonly int $updated = 0,
        public readonly int $deleted = 0,
        public readonly bool $ok = true
    ) {
    }
}
