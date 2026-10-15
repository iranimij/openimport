<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Setup\Patch\Data;

use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * 1.1.2: the sample profiles of earlier installs get a source and a destination that work with their schedules (an
 * uploaded file cannot run on a schedule, a download is no place for a scheduled export). Only samples that still
 * read from an upload or write to a download, with the schedule off, are changed.
 */
class SampleProfilesWithLocations implements DataPatchInterface
{
    /**
     * @param ProfileRepositoryInterface $profiles
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ProfileRepositoryInterface $profiles,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        try {
            $supplier = $this->profiles->getByName('Sample: products from a supplier file');
            if ($supplier->getSourceType() === 'upload' && !$supplier->isActive()) {
                $this->profiles->save($supplier->setSourceType('folder')
                    ->setSourceConfig(['path' => 'supplier/incoming', 'pattern' => '*.csv', 'pick' => 'newest']));
            }
        } catch (NoSuchEntityException $exception) {
            // Deleted by the merchant: nothing to change.
        } catch (LocalizedException $exception) {
            $this->logger->warning('OpenImport: sample profile not updated: ' . $exception->getMessage());
        }
        try {
            $erp = $this->profiles->getByName('Sample: new orders to the ERP');
            $options = $erp->getExportOptions();
            if (($options['destination'] ?? 'download') === 'download' && !$erp->isActive()) {
                $this->profiles->save($erp->setExportOptions(['destination' => 'folder', 'path' => 'exports/erp'] + $options));
            }
        } catch (NoSuchEntityException $exception) {
            // Deleted by the merchant: nothing to change.
        } catch (LocalizedException $exception) {
            $this->logger->warning('OpenImport: sample profile not updated: ' . $exception->getMessage());
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies()
    {
        return [SampleProfiles::class];
    }

    /**
     * @inheritDoc
     */
    public function getAliases()
    {
        return [];
    }
}
