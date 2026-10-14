<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Export\Destination;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Model\Export\DestinationInterface;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Security\FolderSandbox;
use Iranimij\OpenImport\Model\Writer\WriterInterface;
use Magento\Framework\Exception\FileSystemException;

/**
 * A folder on the Magento server (export_options.path: under var/openimport, or an allowed absolute folder;
 * created when missing). An existing file of the same name is overwritten (export_options.overwrite = 1) or kept
 * and the new one gets the date and time added (default).
 */
class FolderDestination implements DestinationInterface
{
    /**
     * @param FolderSandbox $sandbox
     */
    public function __construct(
        private readonly FolderSandbox $sandbox
    ) {
    }

    /**
     * @inheritDoc
     */
    public function deliver(ProfileInterface $profile, RunInterface $run, string $path, WriterInterface $writer): string
    {
        $folder = $this->folder($profile);
        $driver = $this->sandbox->driver();
        $name = (new \SplFileInfo($path))->getFilename();
        $target = $folder . '/' . $name;
        if ($driver->isExists($target) && empty($profile->getExportOptions()['overwrite'])) {
            $info = new \SplFileInfo($name);
            $extension = $info->getExtension();
            $stem = $extension === '' ? $name : substr($name, 0, -strlen($extension) - 1);
            $target = $folder . '/' . $stem . '-' . gmdate('Ymd-His') . ($extension === '' ? '' : '.' . $extension);
        }
        try {
            $driver->copy($path, $target);
        } catch (FileSystemException $exception) {
            throw new PipelineException(sprintf('The file could not be written to %s: %s', $folder, $exception->getMessage()));
        }

        return sprintf('Written to %s.', $target);
    }

    /**
     * @inheritDoc
     */
    public function test(ProfileInterface $profile): string
    {
        $folder = $this->folder($profile);
        if (!$this->sandbox->driver()->isWritable($folder)) {
            throw new PipelineException(sprintf('The folder %s is not writable.', $folder));
        }

        return sprintf('The folder %s exists and is writable.', $folder);
    }

    /**
     * @param ProfileInterface $profile
     * @return string
     * @throws PipelineException
     */
    private function folder(ProfileInterface $profile): string
    {
        $path = (string) ($profile->getExportOptions()['path'] ?? '');
        if (trim($path) === '') {
            throw new PipelineException('Enter the server folder to write to.');
        }

        return $this->sandbox->resolve($path, true);
    }
}
