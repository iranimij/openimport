<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Export;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;

/**
 * The destination of a profile (export_options.destination; default "download").
 */
class DestinationPool
{
    /**
     * @param array<string, DestinationInterface> $destinations type => destination
     */
    public function __construct(
        private readonly array $destinations = []
    ) {
    }

    /**
     * @param ProfileInterface $profile
     * @return DestinationInterface
     * @throws PipelineException
     */
    public function get(ProfileInterface $profile): DestinationInterface
    {
        $type = $this->type($profile);
        $destination = $this->destinations[$type] ?? null;
        if ($destination === null) {
            throw new PipelineException(sprintf('The destination "%s" is not available.', $type));
        }

        return $destination;
    }

    /**
     * @param ProfileInterface $profile
     * @return string
     */
    public function type(ProfileInterface $profile): string
    {
        $type = trim((string) ($profile->getExportOptions()['destination'] ?? ''));

        return $type === '' ? 'download' : $type;
    }

    /**
     * @return string[]
     */
    public function types(): array
    {
        return array_keys($this->destinations);
    }
}
