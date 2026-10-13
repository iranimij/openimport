<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Storage;

use Magento\Framework\App\ResourceConnection;

/**
 * Delta hashes per profile and record (openimport_row_hash): what was last imported for an identifier.
 *
 * Rows are keyed by the SHA-1 of the identifier: the table's collation treats "A" and "Ä" (or "a") as equal, the
 * delta must not.
 */
class RowHashStore
{
    /**
     * Separates identifier, store view and line number in the delta key of extra lines of a record.
     */
    public const SEPARATOR = "\x1F";

    private const TABLE = 'openimport_row_hash';
    private const BATCH = 1000;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Stored hashes for these identifiers.
     *
     * @param int $profileId
     * @param string[] $identifiers
     * @return array<string, string> identifier => hash
     */
    public function fetch(int $profileId, array $identifiers): array
    {
        $connection = $this->resource->getConnection();
        $keys = [];
        foreach (array_unique($identifiers) as $identifier) {
            $keys[$this->key((string) $identifier)] = (string) $identifier;
        }
        $hashes = [];
        foreach (array_chunk(array_keys($keys), self::BATCH) as $chunk) {
            $pairs = $connection->fetchPairs(
                $connection->select()
                    ->from($this->resource->getTableName(self::TABLE), ['identifier_key', 'hash'])
                    ->where('profile_id = ?', $profileId)
                    ->where('identifier_key IN (?)', $chunk)
            );
            foreach ($pairs as $key => $hash) {
                $hashes[$keys[(string) $key]] = (string) $hash;
            }
        }

        return $hashes;
    }

    /**
     * @param int $profileId
     * @param array<string, string> $hashes identifier => hash
     * @param int|null $runId
     * @return void
     */
    public function save(int $profileId, array $hashes, ?int $runId = null): void
    {
        $rows = [];
        foreach ($hashes as $identifier => $hash) {
            $rows[] = [
                'profile_id' => $profileId,
                'identifier_key' => $this->key((string) $identifier),
                'identifier' => mb_substr((string) $identifier, 0, 255),
                'hash' => $hash,
                'run_id' => $runId,
            ];
        }
        $connection = $this->resource->getConnection();
        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            $connection->insertOnDuplicate(
                $this->resource->getTableName(self::TABLE),
                $chunk,
                ['identifier', 'hash', 'run_id']
            );
        }
    }

    /**
     * Removes the hashes of these records, including those of their extra lines (store views, more addresses).
     *
     * @param int $profileId
     * @param string[] $identifiers
     * @return void
     */
    public function delete(int $profileId, array $identifiers): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $identifiers = array_values(array_unique(array_map('strval', $identifiers)));
        $keys = array_map(fn (string $identifier): string => $this->key($identifier), $identifiers);
        foreach (array_chunk($keys, self::BATCH) as $chunk) {
            $connection->delete($table, ['profile_id = ?' => $profileId, 'identifier_key IN (?)' => $chunk]);
        }
        foreach (array_chunk($identifiers, 100) as $chunk) {
            $likes = array_map(
                fn (string $identifier): string => $connection->quoteInto(
                    'identifier LIKE ?',
                    str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $identifier) . self::SEPARATOR . '%'
                ),
                $chunk
            );
            $connection->delete($table, [
                'profile_id = ?' => $profileId,
                '(' . implode(' OR ', $likes) . ')',
            ]);
        }
    }

    /**
     * @param int $profileId
     * @return void
     */
    public function clear(int $profileId): void
    {
        $this->resource->getConnection()->delete(
            $this->resource->getTableName(self::TABLE),
            ['profile_id = ?' => $profileId]
        );
    }

    /**
     * @param int $profileId
     * @return int
     */
    public function count(int $profileId): int
    {
        $connection = $this->resource->getConnection();

        return (int) $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['COUNT(*)'])
                ->where('profile_id = ?', $profileId)
        );
    }

    private function key(string $identifier): string
    {
        return sha1($identifier);
    }
}
