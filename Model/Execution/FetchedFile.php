<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Execution;

/**
 * The file a source delivered for a run.
 */
class FetchedFile
{
    /**
     * @param string $path absolute local path
     * @param string $name original file name (shown on the run, used for format detection)
     * @param string $reference where it came from (remote path), for SourceInterface::finish(); '' = nothing to do
     */
    public function __construct(
        public readonly string $path,
        public readonly string $name,
        public readonly string $reference = ''
    ) {
    }
}
