<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Small;

use Iranimij\OpenImport\Api\Data\ProfileInterface;

/**
 * Search synonym groups as file rows (search_synonyms): the words comma-separated and the scope as "all",
 * "website:code" or "store:code".
 */
class SynonymSource implements SmallSourceInterface
{
    /**
     * @param SmallRecords $records
     */
    public function __construct(private readonly SmallRecords $records)
    {
    }

    /**
     * @inheritDoc
     */
    public function read(ProfileInterface $profile, callable $onHeader, callable $onRow): void
    {
        $onHeader(SynonymImporter::COLUMNS);
        $connection = $this->records->resource()->getConnection();
        $statement = $connection->query(
            $connection->select()
                ->from($this->records->resource()->getTableName('search_synonyms'))
                ->order('group_id ASC')
        );
        while (($row = $statement->fetch()) !== false) {
            $onRow($this->row($row));
        }
    }

    /**
     * @inheritDoc
     */
    public function finish(ProfileInterface $profile, int $runId): void
    {
    }

    /**
     * @inheritDoc
     */
    public function columns(string $entity): array
    {
        return SynonymImporter::COLUMNS;
    }

    /**
     * @inheritDoc
     */
    public function find(string $identifier): ?array
    {
        $words = array_values(array_filter(array_map(
            static fn (string $word): string => mb_strtolower(trim($word)),
            preg_split('/[,;]/', $identifier) ?: []
        )));
        $connection = $this->records->resource()->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->records->resource()->getTableName('search_synonyms'))
                ->order('group_id ASC')
        );
        foreach ($rows as $row) {
            if ($this->words((string) $row['synonyms']) === $words) {
                return $this->row($row);
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function count(): int
    {
        $connection = $this->records->resource()->getConnection();

        return (int) $connection->fetchOne(
            $connection->select()->from($this->records->resource()->getTableName('search_synonyms'), ['COUNT(*)'])
        );
    }

    /**
     * The scope of a file value: "all" or "" (every store view), "website:code", "store:code" (a bare code is tried
     * as a store view, then a website).
     *
     * @param string $text
     * @return array{store_id: int, website_id: int}|null
     */
    public function scope(string $text): ?array
    {
        $text = strtolower(trim($text));
        if ($text === '' || $text === 'all') {
            return ['store_id' => 0, 'website_id' => 0];
        }
        [$kind, $code] = str_contains($text, ':') ? explode(':', $text, 2) : ['', $text];
        $code = trim($code);
        if ($kind !== 'website') {
            $storeId = $this->records->storeCodes()[$code] ?? null;
            if ($storeId !== null) {
                return ['store_id' => $storeId, 'website_id' => $this->records->websiteOf($storeId)];
            }
            if ($kind === 'store') {
                return null;
            }
        }
        $websiteId = $this->records->websites()[$code] ?? null;

        return $websiteId === null ? null : ['store_id' => 0, 'website_id' => $websiteId];
    }

    /**
     * The group of a scope that holds any of the words (Magento merges such groups).
     *
     * @param string[] $words lower-cased
     * @param int $storeId
     * @param int $websiteId
     * @return array<string, mixed>|null the search_synonyms row
     */
    public function group(array $words, int $storeId, int $websiteId): ?array
    {
        $connection = $this->records->resource()->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->records->resource()->getTableName('search_synonyms'))
                ->where('store_id = ?', $storeId)
                ->where('website_id = ?', $websiteId)
                ->order('group_id ASC')
        );
        foreach ($rows as $row) {
            if (array_intersect($this->words((string) $row['synonyms']), $words) !== []) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param string $text
     * @return string[] lower-cased words
     */
    private function words(string $text): array
    {
        return array_values(array_filter(array_map(
            static fn (string $word): string => mb_strtolower(trim($word)),
            explode(',', $text)
        )));
    }

    /**
     * @param array<string, mixed> $group
     * @return array<string, string>
     */
    private function row(array $group): array
    {
        $storeId = (int) $group['store_id'];
        $websiteId = (int) $group['website_id'];
        $scope = 'all';
        if ($storeId !== 0) {
            $scope = 'store:' . $this->records->storeCode($storeId);
        } elseif ($websiteId !== 0) {
            $scope = 'website:' . $this->records->websiteCode($websiteId);
        }

        return ['synonyms' => (string) $group['synonyms'], 'scope' => $scope];
    }
}
