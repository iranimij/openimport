<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Storage;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;

/**
 * A short-lived folder under var/openimport/tmp for one piece of work (a sample, an unpacked file), removed
 * afterwards.
 */
class TempFolder
{
    /**
     * @param Filesystem $filesystem
     */
    public function __construct(
        private readonly Filesystem $filesystem
    ) {
    }

    /**
     * Runs $work with the absolute path of a new empty folder; the folder is removed when $work returns or throws.
     *
     * @param string $prefix folder name prefix (letters, digits, dashes)
     * @param callable(string): mixed $work
     * @return mixed what $work returns
     */
    public function with(string $prefix, callable $work): mixed
    {
        $var = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $relative = 'openimport/tmp/' . $prefix . '-' . bin2hex(random_bytes(6));
        $var->create($relative);
        try {
            return $work(rtrim($var->getAbsolutePath($relative), '/'));
        } finally {
            $var->delete($relative);
        }
    }
}
