<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Category;

/**
 * Category paths in files: names joined with "/", a slash inside a name written as "\/", matched ignoring case.
 */
class CategoryPath
{
    public const SEPARATOR = '/';

    /**
     * "Default Category/Men/Shoes" => ["Default Category", "Men", "Shoes"]
     *
     * @param string $path
     * @return string[]
     */
    public function segments(string $path): array
    {
        $parts = preg_split('/(?<!\\\\)' . preg_quote(self::SEPARATOR, '/') . '/', trim($path)) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $part): string => trim(str_replace('\\' . self::SEPARATOR, self::SEPARATOR, $part)), $parts),
            static fn (string $part): bool => $part !== ''
        ));
    }

    /**
     * @param string[] $segments
     * @return string
     */
    public function join(array $segments): string
    {
        return implode(self::SEPARATOR, array_map(
            static fn (string $name): string => str_replace(self::SEPARATOR, '\\' . self::SEPARATOR, $name),
            $segments
        ));
    }

    /**
     * @param string $path
     * @return string the match key of a path
     */
    public function key(string $path): string
    {
        return mb_strtolower($this->join($this->segments($path)));
    }
}
