<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Setup\Patch\Data;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * Two sample profiles on first install, schedules off, so the form is not empty on day one. They show a German
 * supplier file with store-view columns and a nightly order export; delete or change them freely.
 */
class SampleProfiles implements DataPatchInterface
{
    /**
     * @param ProfileRepositoryInterface $profiles
     * @param ProfileInterfaceFactory $factory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ProfileRepositoryInterface $profiles,
        private readonly ProfileInterfaceFactory $factory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        foreach ($this->samples() as $name => $setup) {
            try {
                $this->profiles->getByName($name);
                continue;
            } catch (NoSuchEntityException $exception) {
                // Not there yet: create it.
            }
            $profile = $this->factory->create()->setName($name)->setIsActive(false)->setNotify('failure');
            $setup($profile);
            try {
                $this->profiles->save($profile);
            } catch (LocalizedException $exception) {
                // A sample must never break setup:upgrade (an entity or store view this shop does not have).
                $this->logger->warning('OpenImport: sample profile not created: ' . $exception->getMessage());
            }
        }

        return $this;
    }

    /**
     * @return array<string, callable(ProfileInterface): void>
     */
    private function samples(): array
    {
        return [
            'Sample: products from a supplier file' => static function (ProfileInterface $profile): void {
                $profile->setDirection(ProfileInterface::DIRECTION_IMPORT)
                    ->setEntity('catalog_product')
                    ->setSourceType('folder')
                    ->setSourceConfig(['path' => 'supplier/incoming', 'pattern' => '*.csv', 'pick' => 'newest'])
                    ->setFormat('auto')
                    ->setBehavior(ProfileInterface::BEHAVIOR_ADD_UPDATE)
                    ->setOnError(ProfileInterface::ON_ERROR_SKIP)
                    ->setAllowedErrors(100)
                    ->setDelta(true)
                    ->setSchedule('0 2 * * *')
                    ->setMapping([
                        ['target' => 'sku', 'source' => 'Artikelnummer', 'transforms' => ['trim']],
                        ['target' => 'name', 'source' => 'Bezeichnung'],
                        ['target' => 'store:de:name', 'source' => 'Bezeichnung DE'],
                        ['target' => 'price', 'source' => 'Preis', 'transforms' => ['decimal_comma']],
                        ['target' => 'qty', 'source' => 'Bestand', 'transforms' => ['number']],
                        ['target' => 'product_online', 'source' => 'Aktiv', 'transforms' => ['map']],
                        ['target' => 'attribute_set_code', 'static' => 'Default'],
                        ['target' => 'product_type', 'static' => 'simple'],
                        ['target' => 'product_websites', 'static' => 'base'],
                    ])
                    ->setValueMaps(['product_online' => ['ja' => '1', 'nein' => '0']]);
            },
            'Sample: new orders to the ERP' => static function (ProfileInterface $profile): void {
                $profile->setDirection(ProfileInterface::DIRECTION_EXPORT)
                    ->setEntity('openimport_order')
                    ->setSourceType('upload')
                    ->setFormat('csv')
                    ->setFormatOptions(['delimiter' => ';', 'bom' => '1'])
                    ->setSchedule('*/15 * * * *')
                    ->setExportOptions([
                        'destination' => 'folder',
                        'path' => 'exports/erp',
                        'file_name' => 'orders-{datetime}.{ext}',
                        'order' => ['rows' => 'item', 'only' => 'new', 'statuses' => 'pending, processing'],
                    ])
                    ->setMapping([
                        ['target' => 'Bestellnummer', 'source' => 'increment_id'],
                        ['target' => 'Datum', 'source' => 'created_at'],
                        ['target' => 'E-Mail', 'source' => 'customer_email'],
                        ['target' => 'Artikel', 'source' => 'item_sku'],
                        ['target' => 'Menge', 'source' => 'item_qty_ordered', 'transforms' => [['name' => 'number', 'args' => ['0']]]],
                        ['target' => 'Preis', 'source' => 'item_price', 'transforms' => [['name' => 'number', 'args' => ['2', ',']]]],
                        ['target' => 'Summe', 'source' => 'grand_total', 'transforms' => [['name' => 'number', 'args' => ['2', ',']]]],
                    ]);
            },
        ];
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases()
    {
        return [];
    }
}
