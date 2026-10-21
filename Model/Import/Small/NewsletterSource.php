<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Small;

use Iranimij\OpenImport\Api\Data\ProfileInterface;

/**
 * Newsletter subscribers as file rows (newsletter_subscriber), for the export and the dry-run preview.
 */
class NewsletterSource implements SmallSourceInterface
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
        $onHeader(NewsletterImporter::COLUMNS);
        $connection = $this->records->resource()->getConnection();
        $statement = $connection->query(
            $connection->select()
                ->from($this->records->resource()->getTableName('newsletter_subscriber'))
                ->order('subscriber_id ASC')
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
        return NewsletterImporter::COLUMNS;
    }

    /**
     * @inheritDoc
     */
    public function find(string $identifier): ?array
    {
        $email = mb_strtolower(trim($identifier));
        $connection = $this->records->resource()->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->records->resource()->getTableName('newsletter_subscriber'))
                ->where('subscriber_email = ?', $email)
                ->order('subscriber_id ASC')
                ->limit(1)
        );

        return is_array($row) ? $this->row($row) : null;
    }

    /**
     * @inheritDoc
     */
    public function count(): int
    {
        $connection = $this->records->resource()->getConnection();

        return (int) $connection->fetchOne(
            $connection->select()->from($this->records->resource()->getTableName('newsletter_subscriber'), ['COUNT(*)'])
        );
    }

    /**
     * The subscriber with an e-mail on a website (store views of the website).
     *
     * @param string $email
     * @param int $websiteId
     * @return array<string, mixed>|null the newsletter_subscriber row
     */
    public function subscriber(string $email, int $websiteId): ?array
    {
        $storeIds = [];
        foreach ($this->records->storeCodes() as $storeId) {
            if ($this->records->websiteOf($storeId) === $websiteId) {
                $storeIds[] = $storeId;
            }
        }
        $connection = $this->records->resource()->getConnection();
        $select = $connection->select()
            ->from($this->records->resource()->getTableName('newsletter_subscriber'))
            ->where('subscriber_email = ?', $email)
            ->order('subscriber_id ASC')
            ->limit(1);
        if ($storeIds !== []) {
            $select->where('store_id IN (?)', $storeIds);
        }
        $row = $connection->fetchRow($select);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $subscriber
     * @return array<string, string>
     */
    private function row(array $subscriber): array
    {
        $status = array_search((int) $subscriber['subscriber_status'], NewsletterImporter::STATUSES, true);

        return [
            'email' => (string) $subscriber['subscriber_email'],
            'store_code' => $this->records->storeCode((int) $subscriber['store_id']),
            'status' => $status === false ? (string) $subscriber['subscriber_status'] : $status,
        ];
    }
}
