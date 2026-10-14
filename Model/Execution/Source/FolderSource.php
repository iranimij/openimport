<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Execution\Source;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Execution\FetchedFile;
use Iranimij\OpenImport\Model\Execution\SourceInterface;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Security\FolderSandbox;
use Magento\Framework\Exception\FileSystemException;

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
    /**
     * @param FolderSandbox $sandbox
     * @param FileSelector $selector
     */
    public function __construct(
        private readonly FolderSandbox $sandbox,
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
        $driver = $this->sandbox->driver();
        $directory = $this->directory($profile);
        $real = $this->sandbox->realPath($reference);
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
        $path = (string) ($profile->getSourceConfig()['path'] ?? '');
        if (trim($path) === '') {
            throw new PipelineException('Enter the server folder to read from.');
        }

        return $this->sandbox->resolve($path);
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
        $driver = $this->sandbox->driver();
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
        if (!$this->sandbox->driver()->isExists($target)) {
            return $target;
        }
        $info = new \SplFileInfo($name);
        $extension = $info->getExtension();
        $stem = $extension === '' ? $name : substr($name, 0, -strlen($extension) - 1);

        return $folder . '/' . $stem . '-' . gmdate('Ymd-His') . ($extension === '' ? '' : '.' . $extension);
    }
}
