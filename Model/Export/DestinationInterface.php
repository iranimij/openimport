<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Export;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Writer\WriterInterface;

/**
 * Where a finished export file goes (download, server folder, SFTP, FTP, e-mail). Register more in di.xml
 * (DestinationPool argument "destinations", keyed by export_options.destination).
 *
 * @api
 */
interface DestinationInterface
{
    /**
     * Delivers the file; it stays in the run folder as well (download from the run page).
     *
     * @param ProfileInterface $profile
     * @param RunInterface $run
     * @param string $path absolute path of the written file
     * @param WriterInterface $writer the writer that made it (content type, extension)
     * @return string what happened, in plain words
     * @throws PipelineException
     */
    public function deliver(ProfileInterface $profile, RunInterface $run, string $path, WriterInterface $writer): string;

    /**
     * Checks the destination settings without exporting ("Test connection").
     *
     * @param ProfileInterface $profile
     * @return string
     * @throws PipelineException
     */
    public function test(ProfileInterface $profile): string;
}
