<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Security;

use Iranimij\OpenImport\Model\Config;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\DriverInterface;

/**
 * The server folders OpenImport may read from or write to: `var/openimport/<path>` for a relative path, or an
 * absolute path inside a folder allowed under Stores › Configuration › iranimij › OpenImport › Safety. Real paths
 * are checked, so symbolic links and ".." cannot lead out.
 */
class FolderSandbox
{
    public const BASE = 'openimport';

    /**
     * @param Filesystem $filesystem
     * @param Config $config
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Config $config
    ) {
    }

    /**
     * @param string $path as entered in the profile
     * @param bool $create create a missing folder (only under var/openimport or an allowed folder)
     * @return string the real absolute path, without trailing slash
     * @throws PipelineException
     */
    public function resolve(string $path, bool $create = false): string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0")) {
            throw new PipelineException('Enter the server folder.');
        }
        if (str_contains('/' . str_replace('\\', '/', $path) . '/', '/../')) {
            throw new PipelineException('The folder may not contain "..".');
        }
        $var = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        if (!str_starts_with($path, '/')) {
            $path = $var->getAbsolutePath(self::BASE . '/' . ltrim($path, '/'));
        }
        $driver = $this->driver();
        $real = $this->realPath($path);
        if ($real === null && $create) {
            $parent = $this->realPath((new \SplFileInfo($path))->getPath());
            if ($parent !== null && $this->inside($parent)) {
                try {
                    $driver->createDirectory(rtrim($path, '/'));
                } catch (FileSystemException $exception) {
                    throw new PipelineException(sprintf('The folder %s could not be created: %s', $path, $exception->getMessage()));
                }
                $real = $this->realPath($path);
            }
        }
        if ($real === null || !$driver->isDirectory($real)) {
            throw new PipelineException(sprintf('The folder %s does not exist.', $path));
        }
        if (!$this->inside($real)) {
            throw new PipelineException(sprintf(
                'The folder %s is outside the allowed folders (var/openimport and the folders allowed in the settings).',
                $real
            ));
        }

        return $real;
    }

    /**
     * @param string $real a real path
     * @return bool
     */
    public function inside(string $real): bool
    {
        foreach ($this->allowedRoots() as $root) {
            if ($real === $root || str_starts_with($real, $root . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[] real paths of var/openimport and the allowed folders
     */
    public function allowedRoots(): array
    {
        $var = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $var->create(self::BASE);
        $roots = [];
        foreach (array_merge([$var->getAbsolutePath(self::BASE)], $this->config->getAllowedFolders()) as $root) {
            $real = $this->realPath((string) $root);
            if ($real !== null) {
                $roots[] = $real;
            }
        }

        return $roots;
    }

    /**
     * @param string $path
     * @return string|null
     */
    public function realPath(string $path): ?string
    {
        $real = (new \SplFileInfo($path))->getRealPath();

        return $real === false ? null : rtrim($real, '/');
    }

    /**
     * @return DriverInterface
     */
    public function driver(): DriverInterface
    {
        return $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)->getDriver();
    }
}
