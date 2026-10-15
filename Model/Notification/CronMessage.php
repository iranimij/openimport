<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Notification;

use Iranimij\OpenImport\Api\Data\RunInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\UrlInterface;

/**
 * System message in the admin while runs wait but OpenImport's cron has not run for ten minutes (or ever).
 */
class CronMessage implements MessageInterface
{
    private const STALE_SECONDS = 600;

    /**
     * @param ResourceConnection $resource
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getIdentity()
    {
        return 'openimport_cron';
    }

    /**
     * @inheritDoc
     */
    public function isDisplayed()
    {
        $connection = $this->resource->getConnection();
        $waiting = (int) $connection->fetchOne(
            $connection->select()->from($this->resource->getTableName('openimport_run'), ['COUNT(*)'])
                ->where('status = ?', RunInterface::STATUS_QUEUED)
        );
        if ($waiting === 0) {
            return false;
        }
        $last = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('cron_schedule'), ['executed_at' => 'MAX(executed_at)'])
                ->where('job_code = ?', 'openimport_tick')
        );

        return !$last || time() - strtotime((string) $last . ' UTC') > self::STALE_SECONDS;
    }

    /**
     * @inheritDoc
     */
    public function getText()
    {
        return (string) __(
            // One literal, so the phrase can be translated.
            // phpcs:ignore Generic.Files.LineLength
            'OpenImport: runs are waiting, but Magento\'s cron has not run OpenImport\'s job in the last 10 minutes. Set up cron (bin/magento cron:run every minute) or run bin/magento openimport:queue:run. <a href="%1">Runs</a>',
            $this->urlBuilder->getUrl('openimport/run/index')
        );
    }

    /**
     * @inheritDoc
     */
    public function getSeverity()
    {
        return self::SEVERITY_MAJOR;
    }
}
