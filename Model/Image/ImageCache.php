<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Image;

use Magento\Framework\App\ResourceConnection;

/**
 * Which web address became which file (table openimport_image), with what the server said about it (ETag,
 * Last-Modified) for conditional requests, when it was last asked and when a run last used it. Times are UTC.
 */
class ImageCache
{
    public const TABLE = 'openimport_image';

    private const CHUNK = 500;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @param string $url
     * @return array{file: string, etag: string, last_modified: string, checked_at: string}|null
     */
    public function find(string $url): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['file', 'etag', 'last_modified', 'checked_at'])
                ->where('url_hash = ?', sha1($url))
        );
        if (!is_array($row)) {
            return null;
        }

        return [
            'file' => (string) $row['file'],
            'etag' => (string) ($row['etag'] ?? ''),
            'last_modified' => (string) ($row['last_modified'] ?? ''),
            'checked_at' => (string) $row['checked_at'],
        ];
    }

    /**
     * Records a fresh download (checked and used now).
     *
     * @param string $url
     * @param string $file relative to the import images folder
     * @param string $etag
     * @param string $lastModified
     * @param int $bytes
     * @return void
     */
    public function store(string $url, string $file, string $etag, string $lastModified, int $bytes): void
    {
        $now = $this->now();
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            [
                'url_hash' => sha1($url),
                'url' => $url,
                'file' => $file,
                'etag' => $etag === '' ? null : substr($etag, 0, 255),
                'last_modified' => $lastModified === '' ? null : substr($lastModified, 0, 64),
                'bytes' => max(0, $bytes),
                'checked_at' => $now,
                'used_at' => $now,
            ],
            ['url', 'file', 'etag', 'last_modified', 'bytes', 'checked_at', 'used_at']
        );
    }

    /**
     * The server said the picture did not change.
     *
     * @param string $url
     * @return void
     */
    public function markChecked(string $url): void
    {
        $now = $this->now();
        $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE),
            ['checked_at' => $now, 'used_at' => $now],
            ['url_hash = ?' => sha1($url)]
        );
    }

    /**
     * Runs used these pictures (keeps them from being pruned).
     *
     * @param string[] $urls
     * @return void
     */
    public function markUsed(array $urls): void
    {
        $hashes = array_values(array_unique(array_map('sha1', $urls)));
        foreach (array_chunk($hashes, self::CHUNK) as $chunk) {
            $this->resource->getConnection()->update(
                $this->resource->getTableName(self::TABLE),
                ['used_at' => $this->now()],
                ['url_hash IN (?)' => $chunk]
            );
        }
    }

    /**
     * Removes the entries no run used since $before.
     *
     * @param \DateTimeImmutable $before
     * @return string[] their files (other entries may still use a file: the caller checks with isUsed())
     */
    public function prune(\DateTimeImmutable $before): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $cut = $before->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $files = $connection->fetchCol($connection->select()->from($table, ['file'])->where('used_at < ?', $cut));
        $connection->delete($table, ['used_at < ?' => $cut]);

        return array_values(array_unique(array_map('strval', $files)));
    }

    /**
     * @param string $file
     * @return bool another entry still points at the file
     */
    public function isUsed(string $file): bool
    {
        $connection = $this->resource->getConnection();

        return (bool) $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['url_hash'])
                ->where('file = ?', $file)
                ->limit(1)
        );
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
