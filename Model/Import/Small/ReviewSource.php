<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Small;

use Iranimij\OpenImport\Api\Data\ProfileInterface;

/**
 * Product reviews as file rows (plain selects over review, review_detail, review_store and the rating votes),
 * for the export and the dry-run preview, plus the rating lookups the import needs.
 */
class ReviewSource implements SmallSourceInterface
{
    /**
     * @var array<string, array{id: int, code: string, options: array<int, int>}>|null lower-cased code => rating
     */
    private ?array $ratings = null;

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
        $onHeader(ReviewImporter::COLUMNS);
        $connection = $this->records->resource()->getConnection();
        $statement = $connection->query($this->select()->order('r.review_id ASC'));
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
        return ReviewImporter::COLUMNS;
    }

    /**
     * @inheritDoc
     */
    public function find(string $identifier): ?array
    {
        [$sku, $nickname, $title] = array_pad(explode('|', $identifier, 3), 3, '');
        $productId = $this->records->productId($sku);
        if ($productId === null) {
            return null;
        }
        $review = $this->byKey($productId, trim($nickname), trim($title));

        return $review === null ? null : $this->row($review);
    }

    /**
     * @inheritDoc
     */
    public function count(): int
    {
        $connection = $this->records->resource()->getConnection();

        return (int) $connection->fetchOne(
            $connection->select()->from($this->records->resource()->getTableName('review'), ['COUNT(*)'])
        );
    }

    /**
     * @param int $id
     * @return array<string, mixed>|null the review and detail columns
     */
    public function byId(int $id): ?array
    {
        $connection = $this->records->resource()->getConnection();
        $row = $connection->fetchRow($this->select()->where('r.review_id = ?', $id));

        return is_array($row) ? $row : null;
    }

    /**
     * @param int $productId
     * @param string $nickname
     * @param string $title
     * @return array<string, mixed>|null
     */
    public function byKey(int $productId, string $nickname, string $title): ?array
    {
        $connection = $this->records->resource()->getConnection();
        $row = $connection->fetchRow(
            $this->select()
                ->where('r.entity_pk_value = ?', $productId)
                ->where('d.nickname = ?', $nickname)
                ->where('d.title = ?', $title)
                ->order('r.review_id ASC')
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @param int $reviewId
     * @return int[] store ids
     */
    public function storesOf(int $reviewId): array
    {
        $connection = $this->records->resource()->getConnection();
        $ids = $connection->fetchCol(
            $connection->select()
                ->from($this->records->resource()->getTableName('review_store'), ['store_id'])
                ->where('review_id = ?', $reviewId)
        );

        return array_map('intval', $ids);
    }

    /**
     * The shop's ratings with their options: option ids by value (1 to 5).
     *
     * @return array<string, array{id: int, code: string, options: array<int, int>}> lower-cased code => rating
     */
    public function ratings(): array
    {
        if ($this->ratings === null) {
            $this->ratings = [];
            $connection = $this->records->resource()->getConnection();
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from(['rt' => $this->records->resource()->getTableName('rating')], ['rating_id', 'rating_code'])
                    ->join(
                        ['o' => $this->records->resource()->getTableName('rating_option')],
                        'o.rating_id = rt.rating_id',
                        ['option_id', 'value']
                    )
                    ->order(['rt.position ASC', 'rt.rating_id ASC', 'o.value ASC'])
            );
            foreach ($rows as $row) {
                $code = mb_strtolower((string) $row['rating_code']);
                $this->ratings[$code] ??= ['id' => (int) $row['rating_id'], 'code' => (string) $row['rating_code'],
                    'options' => []];
                $this->ratings[$code]['options'][(int) $row['value']] = (int) $row['option_id'];
            }
        }

        return $this->ratings;
    }

    /**
     * @param int $reviewId
     * @return void
     */
    public function deleteVotes(int $reviewId): void
    {
        $connection = $this->records->resource()->getConnection();
        $connection->delete(
            $this->records->resource()->getTableName('rating_option_vote'),
            ['review_id = ?' => $reviewId]
        );
    }

    /**
     * @return \Magento\Framework\DB\Select review joined with its detail
     */
    private function select(): \Magento\Framework\DB\Select
    {
        $connection = $this->records->resource()->getConnection();

        return $connection->select()
            ->from(
                ['r' => $this->records->resource()->getTableName('review')],
                ['review_id', 'created_at', 'entity_pk_value', 'status_id']
            )
            ->join(
                ['d' => $this->records->resource()->getTableName('review_detail')],
                'd.review_id = r.review_id',
                ['title', 'detail', 'nickname', 'customer_id', 'store_id']
            );
    }

    /**
     * @param array<string, mixed> $review a row of select()
     * @return array<string, string>
     */
    private function row(array $review): array
    {
        $id = (int) $review['review_id'];
        $connection = $this->records->resource()->getConnection();
        $votes = $connection->fetchAll(
            $connection->select()
                ->from(['v' => $this->records->resource()->getTableName('rating_option_vote')], ['value'])
                ->join(
                    ['rt' => $this->records->resource()->getTableName('rating')],
                    'rt.rating_id = v.rating_id',
                    ['rating_code']
                )
                ->where('v.review_id = ?', $id)
                ->order(['rt.position ASC', 'rt.rating_id ASC'])
        );
        $ratings = [];
        foreach ($votes as $vote) {
            $ratings[] = $vote['rating_code'] . ':' . (int) $vote['value'];
        }
        $status = (string) (array_search((int) $review['status_id'], ReviewImporter::STATUSES, true)
            ?: $review['status_id']);
        $customerId = $review['customer_id'] === null ? null : (int) $review['customer_id'];

        return [
            'review_id' => (string) $id,
            'product_sku' => (string) ($this->records->sku((int) $review['entity_pk_value']) ?? ''),
            'nickname' => (string) $review['nickname'],
            'title' => (string) $review['title'],
            'detail' => (string) $review['detail'],
            'status' => $status,
            'store_codes' => implode(',', array_map(
                fn (int $storeId): string => $this->records->storeCode($storeId),
                $this->storesOf($id)
            )),
            'created_at' => (string) $review['created_at'],
            'customer_email' => $customerId === null ? '' : (string) ($this->records->customerEmail($customerId) ?? ''),
            'ratings' => implode(',', $ratings),
        ];
    }
}
