<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Archive;

/**
 * The file taken out of an archive.
 */
class Unpacked
{
    /**
     * @param string $path absolute path of the unpacked file
     * @param string $name its name inside the archive (without folders), for format detection and the run page
     * @param string $archive the archive's name
     * @param int $files how many files the archive holds
     */
    public function __construct(
        public readonly string $path,
        public readonly string $name,
        public readonly string $archive,
        public readonly int $files
    ) {
    }

    /**
     * @return string e.g. "Read products.csv from feed.zip."
     */
    public function note(): string
    {
        return sprintf('Read %s from %s.', $this->name, $this->archive);
    }
}
