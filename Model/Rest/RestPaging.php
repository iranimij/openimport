<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Rest;

/**
 * How an API hands out its records page by page, and a dotted path into a JSON answer.
 */
class RestPaging
{
    public const NONE = 'none';
    public const PAGE = 'page';
    public const OFFSET = 'offset';
    public const CURSOR = 'cursor';
    public const NEXT_URL = 'next_url';
    public const LINK = 'link';

    public const ALL = [self::NONE, self::PAGE, self::OFFSET, self::CURSOR, self::NEXT_URL, self::LINK];

    /**
     * A value by dotted path ("data.items", "meta.next_cursor", "results.0.id"); "" is the whole answer.
     *
     * @param mixed $data
     * @param string $path
     * @return mixed null when the path leads nowhere
     */
    public function value(mixed $data, string $path): mixed
    {
        $path = trim($path, " .");
        if ($path === '') {
            return $data;
        }
        foreach (explode('.', $path) as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }

        return $data;
    }

    /**
     * The records of an answer: the list at the path, or, without a path, the answer when it is a list or its first
     * list value ({"data": [...]}).
     *
     * @param mixed $answer
     * @param string $path
     * @return array<int, mixed>|null null when there is no list
     */
    public function records(mixed $answer, string $path): ?array
    {
        $found = $this->value($answer, $path);
        if (trim($path) === '' && is_array($found) && !array_is_list($found)) {
            foreach ($found as $value) {
                if (is_array($value) && array_is_list($value)) {
                    return $value;
                }
            }

            return null;
        }

        return is_array($found) && array_is_list($found) ? $found : null;
    }

    /**
     * The next page from a Link header (rel="next").
     *
     * @param string $link
     * @return string ''
     */
    public function nextLink(string $link): string
    {
        foreach (explode(',', $link) as $part) {
            if (preg_match('#<([^>]+)>\s*;(?:[^,]*;)?\s*rel="?next"?#i', trim($part), $match) === 1) {
                return $match[1];
            }
        }

        return '';
    }

    /**
     * Adds query parameters to a path, replacing ones of the same name.
     *
     * @param string $path
     * @param array<string, string> $parameters
     * @return string
     */
    public function withQuery(string $path, array $parameters): string
    {
        [$base, $query] = array_pad(explode('?', $path, 2), 2, '');
        // The address's own query stays as it was, except the names this page sets (compared encoded, as sent).
        $set = array_map('rawurlencode', array_map('strval', array_keys($parameters)));
        $kept = array_filter(
            explode('&', $query),
            static fn (string $pair): bool => $pair !== '' && !in_array(explode('=', $pair, 2)[0], $set, true)
        );
        $added = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

        $parts = array_filter([$added, implode('&', $kept)], static fn ($part) => $part !== '');

        return $base . '?' . implode('&', $parts);
    }
}
