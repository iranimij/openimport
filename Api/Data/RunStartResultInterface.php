<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Api\Data;

/**
 * What the REST API answers about a run.
 *
 * @api
 */
interface RunStartResultInterface
{
    /**
     * @return int
     */
    public function getRunId(): int;

    /**
     * @return string queued, running, success, partial, failed, needs_confirmation or cancelled
     */
    public function getStatus(): string;

    /**
     * @return string dry_run or live
     */
    public function getMode(): string;

    /**
     * @return string the run's summary (empty while queued)
     */
    public function getMessage(): string;

    /**
     * @return int rows read so far
     */
    public function getRowsRead(): int;

    /**
     * @return int errors so far
     */
    public function getErrors(): int;
}
