<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Connection;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Profile;
use Iranimij\OpenImport\Model\ResourceModel\Profile\CollectionFactory;

/**
 * Which profiles use a saved connection: an import whose source type is a connection type and whose source names
 * it, or an export whose destination does.
 */
class ConnectionUsage
{
    /**
     * @param CollectionFactory $profileCollectionFactory
     * @param ConnectionTypes $types
     */
    public function __construct(
        private readonly CollectionFactory $profileCollectionFactory,
        private readonly ConnectionTypes $types
    ) {
    }

    /**
     * @param int $connectionId
     * @return string[] names of the profiles that use the connection
     */
    public function profiles(int $connectionId): array
    {
        return $this->all()[$connectionId] ?? [];
    }

    /**
     * @return array<int, string[]> connection id => names of the profiles that use it
     */
    public function all(): array
    {
        $collection = $this->profileCollectionFactory->create();
        $collection->addFieldToSelect([
            ProfileInterface::PROFILE_ID,
            ProfileInterface::NAME,
            ProfileInterface::DIRECTION,
            ProfileInterface::SOURCE_TYPE,
            ProfileInterface::SOURCE_CONFIG,
            ProfileInterface::EXPORT_OPTIONS,
        ]);
        $like = '%' . ConnectionConfig::ID . '%';
        $collection->getSelect()->where('main_table.source_config LIKE ? OR main_table.export_options LIKE ?', $like);
        $used = [];
        foreach ($collection->getItems() as $profile) {
            if (!$profile instanceof Profile) {
                continue;
            }
            $id = $this->connectionOf($profile);
            if ($id > 0) {
                $used[$id][] = $profile->getName();
            }
        }
        foreach ($used as &$names) {
            sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return $used;
    }

    /**
     * @param ProfileInterface $profile
     * @return int the connection the profile reads from or writes to, 0 for none
     */
    public function connectionOf(ProfileInterface $profile): int
    {
        if ($profile->getDirection() === ProfileInterface::DIRECTION_EXPORT) {
            $options = $profile->getExportOptions();

            return $this->types->has((string) ($options['destination'] ?? ''))
                ? ConnectionConfig::idIn($options)
                : 0;
        }

        return $this->types->has($profile->getSourceType())
            ? ConnectionConfig::idIn($profile->getSourceConfig())
            : 0;
    }
}
