<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\UrlKey;

/**
 * A URL key pattern such as "{name}-{sku}" or "{manufacturer}/{name}": fields in braces are replaced by the row's
 * values; the URL key formatter turns the text into a key.
 */
class UrlKeyPattern
{
    /**
     * @param string $pattern
     * @return string[] the fields the pattern uses, in order
     */
    public function fields(string $pattern): array
    {
        preg_match_all('/\{\s*([A-Za-z0-9_:]+)\s*\}/', $pattern, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param string $pattern
     * @param array<string, string> $row values of the row
     * @param array<string, string> $fallback values used for fields the row leaves empty (the default row)
     * @return string the text to turn into a key ('' when every field is empty)
     */
    public function fill(string $pattern, array $row, array $fallback = []): string
    {
        $filled = false;
        $text = (string) preg_replace_callback(
            '/\{\s*([A-Za-z0-9_:]+)\s*\}/',
            static function (array $match) use ($row, $fallback, &$filled): string {
                $value = trim((string) ($row[$match[1]] ?? ''));
                if ($value === '') {
                    $value = trim((string) ($fallback[$match[1]] ?? ''));
                }
                $filled = $filled || $value !== '';

                return $value;
            },
            $pattern
        );

        return $filled ? $text : '';
    }
}
