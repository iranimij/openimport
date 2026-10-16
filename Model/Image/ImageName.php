<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Image;

/**
 * Where a downloaded picture is kept, relative to the import images folder:
 * openimport/<2 hex>/<8 hex>/<name>.<type>. The folders come from the content's SHA-1, so equal pictures share a
 * file and changed pictures get a new one; the file keeps the name from the address, because Magento names the
 * catalog file after it (good for search engines).
 */
class ImageName
{
    public const FOLDER = 'openimport';

    private const MAX_NAME = 80;

    /**
     * @param string $url
     * @param string $sha1 of the content
     * @param string $type jpg, png or gif
     * @return string
     */
    public function path(string $url, string $sha1, string $type): string
    {
        return sprintf(
            '%s/%s/%s/%s.%s',
            self::FOLDER,
            substr($sha1, 0, 2),
            substr($sha1, 2, 8),
            $this->name($url),
            $type
        );
    }

    /**
     * The last part of the address's path without its extension, as letters, digits, "-" and "_".
     *
     * @param string $url
     * @return string
     */
    public function name(string $url): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- the framework has no URL parser without a request
        $path = (string) parse_url($url, PHP_URL_PATH);
        $slash = strrpos($path, '/');
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- decoding a URL path segment, no framework equivalent
        $last = rawurldecode($slash === false ? $path : substr($path, $slash + 1));
        $dot = strrpos($last, '.');
        if ($dot !== false && $dot > 0) {
            $last = substr($last, 0, $dot);
        }
        if (function_exists('transliterator_transliterate')) {
            $last = (string) transliterator_transliterate('Any-Latin; Latin-ASCII', $last);
        }
        $name = trim((string) preg_replace('/[^a-z0-9_-]+/', '-', strtolower($last)), '-_');
        $name = substr($name, 0, self::MAX_NAME);

        return $name === '' ? 'image' : rtrim($name, '-_');
    }
}
