<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Admin;

use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Model\ResourceModel\Run\CollectionFactory;
use Iranimij\OpenImport\Ui\Component\Listing\Column\StatusChip;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\UrlInterface;
use Iranimij\OpenImport\Model\I18n\StoredText;

/**
 * The last runs of a profile, for the "Recent runs" panel of the profile form.
 */
class RecentRuns
{
    public const LIMIT = 5;

    /**
     * @param CollectionFactory $collectionFactory
     * @param StatusChip $chip
     * @param UrlInterface $urlBuilder
     * @param TimezoneInterface $timezone
     * @param StoredText $text
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly StatusChip $chip,
        private readonly UrlInterface $urlBuilder,
        private readonly TimezoneInterface $timezone,
        private readonly StoredText $text
    ) {
    }

    /**
     * @param int $profileId
     * @return array<int, array<string, string>> newest first: id, url, mode, status, status_html, started, summary
     */
    public function forProfile(int $profileId): array
    {
        if ($profileId <= 0) {
            return [];
        }
        $collection = $this->collectionFactory->create()
            ->addFieldToFilter('profile_id', ['eq' => $profileId])
            ->setOrder('run_id', 'DESC')
            ->setPageSize(self::LIMIT);
        $runs = [];
        /** @var RunInterface $run */
        foreach ($collection as $run) {
            $created = (string) ($run->getCreatedAt() ?? '');
            $runs[] = [
                'id' => (string) $run->getRunId(),
                'url' => $this->urlBuilder->getUrl('openimport/run/view', ['id' => $run->getRunId()]),
                'mode' => (string) ($run->isDryRun() ? __('Dry run') : __('Live run')),
                'status' => $this->chip->label($run->getStatus()),
                'status_html' => $this->chip->chip($run->getStatus()),
                'started' => $created === '' ? '' : $this->timezone->formatDateTime(
                    $created,
                    \IntlDateFormatter::MEDIUM,
                    \IntlDateFormatter::SHORT
                ),
                'summary' => $this->text->translate(strtok($run->getMessage(), "\n") ?: ''),
            ];
        }

        return $runs;
    }
}
