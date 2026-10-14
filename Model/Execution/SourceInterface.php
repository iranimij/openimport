<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Execution;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;

/**
 * A place a profile reads its file from (upload, server folder, URL, SFTP, FTP). Register more in di.xml
 * (argument "sources" of SourceResolver, keyed by source type).
 *
 * @api
 */
interface SourceInterface
{
    /**
     * Downloads or locates the file to import.
     *
     * @param ProfileInterface $profile
     * @param string $folder the run folder; downloads go there
     * @return FetchedFile
     * @throws PipelineException with a plain-words reason
     */
    public function fetch(ProfileInterface $profile, string $folder): FetchedFile;

    /**
     * Checks the settings without importing anything ("Test connection").
     *
     * @param ProfileInterface $profile
     * @return string what was found, in plain words
     * @throws PipelineException with a plain-words reason
     */
    public function test(ProfileInterface $profile): string;

    /**
     * After a successful live import: keeps, moves or deletes the file the run read (profile's "after success").
     *
     * @param ProfileInterface $profile
     * @param string $reference FetchedFile::$reference of that run
     * @return void
     * @throws PipelineException
     */
    public function finish(ProfileInterface $profile, string $reference): void;
}
