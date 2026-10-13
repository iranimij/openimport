<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Cron;

use Iranimij\OpenImport\Model\Maintenance\Housekeeping;

/**
 * Hourly: run files past their keep time, unused uploads, old schedule records, runs whose process died.
 */
class Cleanup
{
    /**
     * @param Housekeeping $housekeeping
     */
    public function __construct(
        private readonly Housekeeping $housekeeping
    ) {
    }

    public function execute(): void
    {
        $this->housekeeping->run();
    }
}
