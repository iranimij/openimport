<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Api;

use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\Data\RunStartResultInterface;

/**
 * A run as the REST API shows it.
 */
class RunStartResult implements RunStartResultInterface
{
    /**
     * @param RunInterface $run
     */
    public function __construct(
        private readonly RunInterface $run
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getRunId(): int
    {
        return (int) $this->run->getRunId();
    }

    /**
     * @inheritDoc
     */
    public function getStatus(): string
    {
        return (string) $this->run->getStatus();
    }

    /**
     * @inheritDoc
     */
    public function getMode(): string
    {
        return $this->run->isDryRun() ? RunInterface::MODE_DRY_RUN : RunInterface::MODE_LIVE;
    }

    /**
     * @inheritDoc
     */
    public function getMessage(): string
    {
        return (string) $this->run->getMessage();
    }

    /**
     * @inheritDoc
     */
    public function getRowsRead(): int
    {
        return $this->run->getCount('read');
    }

    /**
     * @inheritDoc
     */
    public function getErrors(): int
    {
        return $this->run->getCount('errors');
    }
}
