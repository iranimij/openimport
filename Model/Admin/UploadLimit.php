<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Admin;

/**
 * The largest upload PHP accepts here: the smaller of upload_max_filesize and post_max_size. The web server in front
 * (nginx client_max_body_size, Apache LimitRequestBody) may accept less; PHP cannot see that limit.
 */
class UploadLimit
{
    /**
     * @param string|null $uploadMax upload_max_filesize (null: read from PHP)
     * @param string|null $postMax post_max_size (null: read from PHP)
     */
    public function __construct(
        private readonly ?string $uploadMax = null,
        private readonly ?string $postMax = null
    ) {
    }

    /**
     * @return int bytes, 0 for no limit
     */
    public function bytes(): int
    {
        $limits = array_filter([
            $this->toBytes($this->uploadMax ?? (string) ini_get('upload_max_filesize')),
            $this->toBytes($this->postMax ?? (string) ini_get('post_max_size')),
        ], static fn (int $bytes): bool => $bytes > 0);

        return $limits === [] ? 0 : min($limits);
    }

    /**
     * @return string "8 MB", "" for no limit
     */
    public function text(): string
    {
        $bytes = $this->bytes();
        if ($bytes === 0) {
            return '';
        }
        $megabytes = $bytes / 1048576;

        return ($megabytes >= 1 ? (string) round($megabytes, $megabytes < 10 ? 1 : 0) : '< 1') . ' MB';
    }

    /**
     * @param string $value a php.ini size such as "2M", "512K", "1G" or "1048576"
     * @return int
     */
    private function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^(\d+(?:\.\d+)?)\s*([kmg]?)b?$/i', $value, $match)) {
            return 0;
        }
        $number = (float) $match[1];

        return (int) match (strtolower($match[2])) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
