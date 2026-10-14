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
use Iranimij\OpenImport\Model\Writer\WriterInterface;

/**
 * The file stays in the run folder; the run page offers it for download (until the run files are cleaned up).
 */
class DownloadDestination implements DestinationInterface
{
    /**
     * @inheritDoc
     */
    public function deliver(ProfileInterface $profile, RunInterface $run, string $path, WriterInterface $writer): string
    {
        return 'Download it from the run page.';
    }

    /**
     * @inheritDoc
     */
    public function test(ProfileInterface $profile): string
    {
        return 'The file will be offered for download on the run page.';
    }
}
