<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Setup\Patch\Data;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * 1.5.2 moved "download the run's files" into its own permission (Iranimij_OpenImport::run_files). Roles that could
 * view runs before keep downloading; new roles get it only when an administrator ticks it.
 */
class GrantRunFiles implements DataPatchInterface
{
    private const VIEW = 'Iranimij_OpenImport::runs';
    private const FILES = 'Iranimij_OpenImport::run_files';

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('authorization_rule');
        $roles = $connection->fetchCol(
            $connection->select()->from($table, ['role_id'])
                ->where('resource_id = ?', self::VIEW)
                ->where('permission = ?', 'allow')
        );
        $done = array_map('intval', $connection->fetchCol(
            $connection->select()->from($table, ['role_id'])->where('resource_id = ?', self::FILES)
        ));
        foreach (array_diff(array_map('intval', $roles), $done) as $roleId) {
            $connection->insert(
                $table,
                ['role_id' => $roleId, 'resource_id' => self::FILES, 'privileges' => null, 'permission' => 'allow']
            );
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
