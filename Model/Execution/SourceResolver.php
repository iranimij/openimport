<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Execution;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;

/**
 * Finds the file a run reads: a file given to this run (upload, CLI --file) or the profile's source.
 * Profile sources (folder, URL, SFTP, FTP) plug in through di.xml (argument "sources", SourceInterface).
 */
class SourceResolver
{
    /**
     * @param array<string, SourceInterface> $sources source type => source
     */
    public function __construct(
        private readonly array $sources = []
    ) {
    }

    /**
     * @param ProfileInterface $profile
     * @param RunInterface $run
     * @param string $folder the run folder (downloads go there)
     * @return FetchedFile
     * @throws PipelineException
     */
    public function fetch(ProfileInterface $profile, RunInterface $run, string $folder): FetchedFile
    {
        $options = $run->getOptions();
        $file = isset($options['file']) && is_string($options['file']) ? $options['file'] : '';
        if ($file !== '') {
            $info = new \SplFileInfo($file);
            if (!$info->isFile() || !$info->isReadable()) {
                throw new PipelineException(sprintf('The file %s cannot be read.', $info->getFilename()));
            }
            $name = isset($options['original_name']) && is_string($options['original_name'])
                ? $options['original_name']
                : $info->getFilename();

            return new FetchedFile($file, $name);
        }

        return $this->source($profile)->fetch($profile, $folder);
    }

    /**
     * The profile's source.
     *
     * @param ProfileInterface $profile
     * @return SourceInterface
     * @throws PipelineException
     */
    public function source(ProfileInterface $profile): SourceInterface
    {
        $source = $this->sources[$profile->getSourceType()] ?? null;
        if ($source === null) {
            throw new PipelineException(
                $profile->getSourceType() === 'upload'
                    ? 'Upload a file to run this profile.'
                    : sprintf('The source type "%s" is not available.', $profile->getSourceType())
            );
        }

        return $source;
    }
}
