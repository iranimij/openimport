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
 * A source that can list its matching files and read a given one: "All matching files" runs one run per file.
 *
 * @api
 */
interface MultiFileSourceInterface extends SourceInterface
{
    /**
     * The files that match the profile's pattern, in name order.
     *
     * @param ProfileInterface $profile
     * @return string[]
     * @throws PipelineException
     */
    public function names(ProfileInterface $profile): array;

    /**
     * Downloads or locates one of the files names() listed.
     *
     * @param ProfileInterface $profile
     * @param string $folder the run folder
     * @param string $name
     * @return FetchedFile
     * @throws PipelineException when the file is gone
     */
    public function fetchNamed(ProfileInterface $profile, string $folder, string $name): FetchedFile;
}
