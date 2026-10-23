<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Chain;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * The "then run profile …" links between profiles, read with plain selects (the validator runs inside the
 * repository, which cannot be injected there).
 */
class ChainLinks
{
    public const MAX_LENGTH = 20;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @param int $profileId
     * @return array{id: int, name: string, direction: string, source_type: string, then_profile_id: int|null,
     *     then_when: string, is_active: bool}|null
     */
    public function profile(int $profileId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from(
                    $this->resource->getTableName('openimport_profile'),
                    ['profile_id', 'name', 'direction', 'source_type', 'then_profile_id', 'then_when', 'is_active']
                )
                ->where('profile_id = ?', $profileId)
        );
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['profile_id'],
            'name' => (string) $row['name'],
            'direction' => (string) $row['direction'],
            'source_type' => (string) $row['source_type'],
            'then_profile_id' => $row['then_profile_id'] === null ? null : (int) $row['then_profile_id'],
            'then_when' => (string) $row['then_when'] === ProfileInterface::THEN_ALWAYS
                ? ProfileInterface::THEN_ALWAYS
                : ProfileInterface::THEN_SUCCESS,
            'is_active' => (bool) $row['is_active'],
        ];
    }

    /**
     * The chain a profile would start, following the links: the profile itself first, then each next profile,
     * stopped at a repeated profile (a loop, reported by the caller) or at MAX_LENGTH links.
     *
     * @param int $firstId
     * @param int|null $firstNext the first profile's link as it is about to be saved (null = as stored)
     * @return array{0: int[], 1: int|null} the profile ids in order, and the id that would run again (a loop)
     */
    public function path(int $firstId, ?int $firstNext = null): array
    {
        $ids = [$firstId];
        $next = $firstNext ?? ($this->profile($firstId)['then_profile_id'] ?? null);
        while ($next !== null) {
            if (in_array($next, $ids, true) || count($ids) >= self::MAX_LENGTH) {
                return [$ids, $next];
            }
            $ids[] = $next;
            $next = $this->profile($next)['then_profile_id'] ?? null;
        }

        return [$ids, null];
    }

    /**
     * Profiles whose chain names a profile (for the admin: "runs after …").
     *
     * @param int $profileId
     * @return string[] names
     */
    public function predecessors(int $profileId): array
    {
        $connection = $this->resource->getConnection();
        $names = $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('openimport_profile'), ['name'])
                ->where('then_profile_id = ?', $profileId)
                ->order('name ASC')
        );

        return array_map('strval', $names);
    }
}
