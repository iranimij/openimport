<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Small;

use Iranimij\OpenImport\Api\Data\ProfileInterface;

/**
 * Search terms as file rows (search_query), for the export and the dry-run preview.
 */
class SearchTermSource implements SmallSourceInterface
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
        $onHeader(SearchTermImporter::COLUMNS);
        $connection = $this->records->resource()->getConnection();
        $statement = $connection->query(
            $connection->select()
                ->from($this->records->resource()->getTableName('search_query'))
                ->order(['store_id ASC', 'query_text ASC'])
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
        return SearchTermImporter::COLUMNS;
    }

    /**
     * @inheritDoc
     */
    public function find(string $identifier): ?array
    {
        $storeId = $this->records->storeId('');
        $term = $storeId === null ? null : $this->term(trim($identifier), $storeId);

        return $term === null ? null : $this->row($term);
    }

    /**
     * @inheritDoc
     */
    public function count(): int
    {
        $connection = $this->records->resource()->getConnection();

        return (int) $connection->fetchOne(
            $connection->select()->from($this->records->resource()->getTableName('search_query'), ['COUNT(*)'])
        );
    }

    /**
     * @param string $text
     * @param int $storeId
     * @return array<string, mixed>|null the search_query row
     */
    public function term(string $text, int $storeId): ?array
    {
        $connection = $this->records->resource()->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->records->resource()->getTableName('search_query'))
                ->where('query_text = ?', $text)
                ->where('store_id = ?', $storeId)
                ->limit(1)
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $term
     * @return array<string, string>
     */
    private function row(array $term): array
    {
        return [
            'query_text' => (string) $term['query_text'],
            'store_code' => $this->records->storeCode((int) $term['store_id']),
            'redirect' => (string) ($term['redirect'] ?? ''),
            'display_in_terms' => (string) (int) $term['display_in_terms'],
            'popularity' => (string) (int) $term['popularity'],
            'num_results' => (string) (int) $term['num_results'],
        ];
    }
}
