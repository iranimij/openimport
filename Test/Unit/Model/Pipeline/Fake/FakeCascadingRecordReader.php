<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Pipeline\Fake;

use Iranimij\OpenImport\Model\Entity\CascadingRecordsInterface;

/**
 * A shop in memory whose records have children that a delete takes along.
 */
class FakeCascadingRecordReader extends FakeRecordReader implements CascadingRecordsInterface
{
    /**
     * @var array<string, int> lower-cased identifier => number of children
     */
    public array $children = [];

    /**
     * @inheritDoc
     */
    public function affectedByDelete(array $identifiers): int
    {
        $count = 0;
        foreach ($identifiers as $identifier) {
            $count += 1 + ($this->children[mb_strtolower($identifier)] ?? 0);
        }

        return $count;
    }
}
