<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Bridge;

/**
 * One problem core reported. The row is the 0-based position in the rows OpenImport handed to core
 * (null for errors that concern the whole import, such as a missing required column).
 */
class RowError
{
    /**
     * @param int|null $row
     * @param string $code
     * @param string $message
     * @param string|null $column
     * @param bool $critical
     * @param bool $imported the row was imported all the same (a note, not a refusal)
     */
    public function __construct(
        public readonly ?int $row,
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $column,
        public readonly bool $critical,
        public readonly bool $imported = false
    ) {
    }
}
