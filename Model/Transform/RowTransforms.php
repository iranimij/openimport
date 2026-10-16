<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Transform;

/**
 * Transforms that look at earlier rows of the file.
 *
 * fill_down([group column]): an empty value takes the last non-empty value above it, within rows that have the same
 * value in the group column (Shopify writes a product's title only on its first variant row, keyed by "Handle").
 * The rows of a group must follow each other; the memory lasts for one run.
 */
class RowTransforms implements TransformProviderInterface
{
    /**
     * @inheritDoc
     */
    public function names(): array
    {
        return ['fill_down'];
    }

    /**
     * @inheritDoc
     */
    public function compile(string $name, Arguments $arguments, TransformScope $scope): \Closure
    {
        $arguments->expectCount(0, 1);
        $group = $arguments->count() > 0 ? $arguments->string(0, '') : '';
        $last = [];

        return static function (string $value, array $row) use ($group, &$last): string {
            $key = $group === '' ? '' : trim((string) ($row[$group] ?? ''));
            if (trim($value) !== '') {
                // Only the latest group is kept: a group's rows follow each other, and memory stays flat.
                $last = [$key => $value];

                return $value;
            }

            return $last[$key] ?? '';
        };
    }
}
