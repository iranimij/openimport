<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Setup;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

/**
 * bin/magento module:uninstall Iranimij_OpenImport --remove-data: every table, every setting, every cron row and the
 * run folders go; Magento's own import history and the products, customers and orders that were imported stay.
 */
class Uninstall implements UninstallInterface
{
    private const TABLES = ['openimport_export_mark', 'openimport_lock', 'openimport_row_hash', 'openimport_run_preview',
        'openimport_run_error', 'openimport_run', 'openimport_value_map', 'openimport_mapping', 'openimport_profile'];

    /**
     * @param Filesystem $filesystem
     */
    public function __construct(
        private readonly Filesystem $filesystem
    ) {
    }

    /**
     * @inheritDoc
     */
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();
        $connection = $setup->getConnection();
        foreach (self::TABLES as $table) {
            $connection->dropTable($setup->getTable($table));
        }
        $connection->delete($setup->getTable('core_config_data'), ['path LIKE ?' => 'openimport/%']);
        $connection->delete($setup->getTable('cron_schedule'), ['job_code LIKE ?' => 'openimport_%']);
        $setup->endSetup();
        try {
            $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)->delete('openimport');
        } catch (\Exception $exception) {
            // A folder that cannot be removed is not worth failing the uninstall for.
        }
    }
}
