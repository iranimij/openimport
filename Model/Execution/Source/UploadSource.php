<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Execution\Source;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Admin\UploadStore;
use Iranimij\OpenImport\Model\Execution\FetchedFile;
use Iranimij\OpenImport\Model\Execution\SourceInterface;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;

/**
 * Upload profiles read the file uploaded last in the profile form (also on a schedule).
 */
class UploadSource implements SourceInterface
{
    /**
     * @param UploadStore $uploads
     */
    public function __construct(
        private readonly UploadStore $uploads
    ) {
    }

    /**
     * @inheritDoc
     */
    public function fetch(ProfileInterface $profile, string $folder): FetchedFile
    {
        $config = $profile->getSourceConfig();
        $path = $this->uploads->path((string) ($config['file'] ?? ''));
        if ($path === null) {
            throw new PipelineException('Upload a file to run this profile.');
        }

        return new FetchedFile($path, (string) ($config['file_name'] ?? 'upload'));
    }

    /**
     * @inheritDoc
     */
    public function test(ProfileInterface $profile): string
    {
        $config = $profile->getSourceConfig();
        if ($this->uploads->path((string) ($config['file'] ?? '')) === null) {
            throw new PipelineException('No file is uploaded yet.');
        }

        return sprintf('The uploaded file %s is ready.', (string) ($config['file_name'] ?? 'upload'));
    }

    /**
     * Uploaded files stay: the next run of the profile reads the same file until another one is uploaded.
     *
     * @inheritDoc
     */
    public function finish(ProfileInterface $profile, string $reference): void
    {
    }
}
