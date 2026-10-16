<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Image;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\ImportExport\Model\Import\ImageDirectoryBaseProvider;

/**
 * The import images folder (Stores › Configuration › Advanced › System › Images Upload Directory,
 * var/import/images by default): the folder core's admin import reads pictures from. Product runs give it to core,
 * so file names in a file are found there, and downloaded pictures are kept in its openimport sub folder.
 */
class ImageFolder
{
    /**
     * @param Filesystem $filesystem
     * @param ImageDirectoryBaseProvider $baseProvider
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly ImageDirectoryBaseProvider $baseProvider
    ) {
    }

    /**
     * The folder relative to the Magento root.
     *
     * @return string
     */
    public function relativeBase(): string
    {
        $base = trim((string) $this->baseProvider->getDirectoryRelativePath(), '/');

        return $base === '' ? 'var/import/images' : $base;
    }

    /**
     * The folder's absolute path; it is created when missing (core refuses a folder that does not exist).
     *
     * @return string
     */
    public function absoluteBase(): string
    {
        $root = $this->root();
        $root->create($this->relativeBase());

        return rtrim($root->getAbsolutePath($this->relativeBase()), '/');
    }

    /**
     * @param string $file relative to the images folder
     * @return string
     */
    public function absolute(string $file): string
    {
        return $this->absoluteBase() . '/' . ltrim($file, '/');
    }

    /**
     * @param string $file relative to the images folder
     * @return bool
     */
    public function exists(string $file): bool
    {
        return $this->root()->isFile($this->relativeBase() . '/' . ltrim($file, '/'));
    }

    /**
     * @param string $file relative to the images folder
     * @return void
     */
    public function delete(string $file): void
    {
        $path = $this->relativeBase() . '/' . ltrim($file, '/');
        if ($this->root()->isFile($path)) {
            $this->root()->delete($path);
        }
    }

    /**
     * @param string $folder relative to the images folder
     * @return void
     */
    public function create(string $folder): void
    {
        $this->root()->create($this->relativeBase() . '/' . trim($folder, '/'));
    }

    private function root(): WriteInterface
    {
        return $this->filesystem->getDirectoryWrite(DirectoryList::ROOT);
    }
}
