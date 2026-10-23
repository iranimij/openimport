<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Profile;

use Iranimij\OpenImport\Model\Chain\ChainLinks;

/**
 * Chain links of an in-memory shop: id => [name, direction, source_type, then_profile_id].
 */
class FakeChainLinks extends ChainLinks
{
    /**
     * @param array<int, array{name: string, direction?: string, source_type?: string, then?: int|null}> $profiles
     */
    // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
    public function __construct(private readonly array $profiles = [])
    {
    }

    public function profile(int $profileId): ?array
    {
        $row = $this->profiles[$profileId] ?? null;
        if ($row === null) {
            return null;
        }

        return [
            'id' => $profileId,
            'name' => $row['name'],
            'direction' => $row['direction'] ?? 'export',
            'source_type' => $row['source_type'] ?? 'upload',
            'then_profile_id' => $row['then'] ?? null,
            'then_when' => 'success',
            'is_active' => true,
        ];
    }

    public function predecessors(int $profileId): array
    {
        $names = [];
        foreach ($this->profiles as $row) {
            if (($row['then'] ?? null) === $profileId) {
                $names[] = $row['name'];
            }
        }

        return $names;
    }
}
