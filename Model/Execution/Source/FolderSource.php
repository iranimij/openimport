<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Execution\Source;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Config;
use Iranimij\OpenImport\Model\Execution\FetchedFile;
use Iranimij\OpenImport\Model\Execution\SourceInterface;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\DriverInterface;

/**
 * A folder on the Magento server, sandboxed: `var/openimport/<path>` for a relative path, or an absolute path inside
 * a folder allowed under Stores › Configuration › iranimij › OpenImport › Safety. Symbolic links that lead out of
 * the allowed folders are refused (the real path is checked).
 *
 * source_config: path, pattern (default *), pick (newest | oldest | first_name | last_name), move_to (default
 * "processed", for after success = move).
 */
class FolderSource implements SourceInterface
{
    public const BASE = 'openimport';

    /**
     * @param Filesystem $filesystem
     * @param Config $config
     * @param FileSelector $selector
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Config $config,
        private readonly FileSelector $selector
    ) {
    }

    /**
     * @inheritDoc
     */
    public function fetch(ProfileInterface $profile, string $folder): FetchedFile
    {
        $directory = $this->directory($profile);
        $config = $profile->getSourceConfig();
        $name = $this->selector->pick(
            $this->files($directory),
            (string) ($config['pattern'] ?? '*'),
            (string) ($config['pick'] ?? FileSelector::PICK_NEWEST)
        );

        return new FetchedFile($directory . '/' . $name, $name, $directory . '/' . $name);
    }

    /**
     * @inheritDoc
     */
    public function test(ProfileInterface $profile): string
    {
        $directory = $this->directory($profile);
        $config = $profile->getSourceConfig();
        $files = $this->files($directory);
        $matching = $this->selector->matching($files, (string) ($config['pattern'] ?? '*'));
        if ($matching === []) {
            return sprintf('The folder %s exists, but no file matches the pattern yet (%d file(s) in it).', $directory, count($files));
        }
        $next = $this->selector->pick($files, (string) ($config['pattern'] ?? '*'), (string) ($config['pick'] ?? ''));

        return sprintf('The folder %s exists. %d file(s) match; the next run reads %s.', $directory, count($matching), $next);
    }

    /**
     * @inheritDoc
     */
    public function finish(ProfileInterface $profile, string $reference): void
    {
        $action = $profile->getAfterSuccess();
        if ($reference === '' || $action === 'keep') {
            return;
        }
        $driver = $this->driver();
        $directory = $this->directory($profile);
        $real = $this->realPath($reference);
        if ($real === null || !str_starts_with($real, $directory . '/')) {
            throw new PipelineException('The imported file is no longer in the source folder.');
        }
        try {
            if ($action === 'delete') {
                $driver->deleteFile($real);
                return;
            }
            $target = $directory . '/' . $this->moveTo($profile);
            $driver->createDirectory($target);
            $driver->rename($real, $this->freeName($target, (new \SplFileInfo($real))->getFilename()));
        } catch (FileSystemException $exception) {
            throw new PipelineException('The imported file could not be moved or deleted: ' . $exception->getMessage());
        }
    }

    /**
     * The profile's folder as a real absolute path, checked against the allowed folders.
     *
     * @param ProfileInterface $profile
     * @return string
     * @throws PipelineException
     */
    public function directory(ProfileInterface $profile): string
    {
        $path = trim((string) ($profile->getSourceConfig()['path'] ?? ''));
        if ($path === '' || str_contains($path, "\0")) {
            throw new PipelineException('Enter the server folder to read from.');
        }
        $var = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        if (!str_starts_with($path, '/')) {
            $relative = self::BASE . '/' . ltrim($path, '/');
            if (str_contains('/' . $relative . '/', '/../')) {
                throw new PipelineException('The folder may not contain "..".');
            }
            $path = $var->getAbsolutePath($relative);
        }
        $real = $this->realPath($path);
        if ($real === null || !$this->driver()->isDirectory($real)) {
            throw new PipelineException(sprintf('The folder %s does not exist.', $path));
        }
        foreach ($this->allowedRoots() as $root) {
            if ($real === $root || str_starts_with($real, $root . '/')) {
                return $real;
            }
        }
        throw new PipelineException(sprintf(
            'The folder %s is outside the allowed folders (var/openimport and the folders allowed in the settings).',
            $real
        ));
    }

    /**
     * Files (not folders) of a directory with their modification times.
     *
     * @param string $directory
     * @return array<string, int|null>
     * @throws PipelineException
     */
    private function files(string $directory): array
    {
        $driver = $this->driver();
        try {
            $files = [];
            foreach ($driver->readDirectory($directory) as $path) {
                if ($driver->isFile($path)) {
                    $stat = $driver->stat($path);
                    $files[(new \SplFileInfo($path))->getFilename()] = isset($stat['mtime']) ? (int) $stat['mtime'] : null;
                }
            }
        } catch (FileSystemException $exception) {
            throw new PipelineException('The folder cannot be read: ' . $exception->getMessage());
        }

        return $files;
    }

    /**
     * @return string[] real paths of var/openimport and the allowed folders
     */
    private function allowedRoots(): array
    {
        $var = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $var->create(self::BASE);
        $roots = [];
        foreach (array_merge([$var->getAbsolutePath(self::BASE)], $this->config->getAllowedFolders()) as $root) {
            $real = $this->realPath((string) $root);
            if ($real !== null) {
                $roots[] = rtrim($real, '/');
            }
        }

        return $roots;
    }

    /**
     * @param ProfileInterface $profile
     * @return string the subfolder processed files are moved to
     */
    private function moveTo(ProfileInterface $profile): string
    {
        $moveTo = trim((string) ($profile->getSourceConfig()['move_to'] ?? ''), '/');

        return $moveTo === '' || str_contains('/' . $moveTo . '/', '/../') ? 'processed' : $moveTo;
    }

    /**
     * A name in the folder that is not taken yet (adds the date and time when it is).
     *
     * @param string $folder
     * @param string $name
     * @return string absolute path
     */
    private function freeName(string $folder, string $name): string
    {
        $target = $folder . '/' . $name;
        if (!$this->driver()->isExists($target)) {
            return $target;
        }
        $info = new \SplFileInfo($name);
        $extension = $info->getExtension();
        $stem = $extension === '' ? $name : substr($name, 0, -strlen($extension) - 1);

        return $folder . '/' . $stem . '-' . gmdate('Ymd-His') . ($extension === '' ? '' : '.' . $extension);
    }

    /**
     * @param string $path
     * @return string|null
     */
    private function realPath(string $path): ?string
    {
        $real = (new \SplFileInfo($path))->getRealPath();

        return $real === false ? null : rtrim($real, '/');
    }

    /**
     * @return DriverInterface
     */
    private function driver(): DriverInterface
    {
        return $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)->getDriver();
    }
}
