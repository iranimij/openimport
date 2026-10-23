<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Small;

use Magento\ImportExport\Model\Import;
use Magento\Search\Model\Query;
use Magento\Search\Model\QueryFactory;
use Magento\Search\Model\ResourceModel\Query as QueryResource;
use Psr\Log\LoggerInterface;

/**
 * Search terms (Marketing › Search Terms) by query text and store view: redirect URL, shown in the popular
 * terms, popularity. Written through the search query model.
 */
class SearchTermImporter extends SmallImporter
{
    public const COLUMNS = ['query_text', 'store_code', 'redirect', 'display_in_terms', 'popularity', 'num_results'];

    /**
     * @param LoggerInterface $logger
     * @param SmallRecords $records
     * @param SearchTermSource $source
     * @param QueryFactory $queryFactory
     * @param QueryResource $queryResource
     */
    public function __construct(
        LoggerInterface $logger,
        private readonly SmallRecords $records,
        private readonly SearchTermSource $source,
        private readonly QueryFactory $queryFactory,
        private readonly QueryResource $queryResource
    ) {
        parent::__construct($logger);
    }

    /**
     * @inheritDoc
     */
    protected function what(): string
    {
        return 'Search terms';
    }

    /**
     * @inheritDoc
     */
    protected function prepare(array $row, string $behavior): array
    {
        $text = $row['query_text'] ?? '';
        if ($text === '') {
            return ['error' => ['query_text', 'the search term is empty']];
        }
        $storeId = $this->records->storeId($row['store_code'] ?? '');
        if ($storeId === null) {
            return ['error' => ['store_code', sprintf('there is no store view "%s"', $row['store_code'] ?? '')]];
        }
        $existing = $this->source->term($text, $storeId);
        $key = $storeId . '|' . mb_strtolower($text);
        if ($behavior === Import::BEHAVIOR_DELETE) {
            if ($existing === null) {
                return ['error' => ['query_text', 'there is no such search term in this store view']];
            }

            return ['key' => $key, 'exists' => true, 'id' => (int) $existing['query_id']];
        }
        $display = null;
        if (($row['display_in_terms'] ?? '') !== '') {
            $display = $this->flag($row['display_in_terms']);
            if ($display === null) {
                return ['error' => ['display_in_terms', 'use 1 or 0 (also yes/no, true/false)']];
            }
        }
        if (($row['popularity'] ?? '') !== '' && !ctype_digit($row['popularity'])) {
            return ['error' => ['popularity', sprintf('"%s" is not a whole number', $row['popularity'])]];
        }

        return [
            'key' => $key,
            'exists' => $existing !== null,
            'id' => $existing === null ? null : (int) $existing['query_id'],
            'query_text' => $text,
            'store_id' => $storeId,
            'redirect' => $row['redirect'] ?? '',
            'redirect_given' => array_key_exists('redirect', $row),
            'display_in_terms' => $display,
            'popularity' => ($row['popularity'] ?? '') !== '' ? (int) $row['popularity'] : null,
        ];
    }

    /**
     * @inheritDoc
     */
    protected function write(array $prepared): void
    {
        /** @var Query $query */
        $query = $this->queryFactory->create();
        if ($prepared['id'] !== null) {
            $this->queryResource->load($query, (int) $prepared['id']);
        } else {
            $query->setQueryText($prepared['query_text']);
            $query->setStoreId($prepared['store_id']);
            $query->setData([
                'is_active' => 1, 'is_processed' => 1, 'popularity' => 0, 'num_results' => 0, 'display_in_terms' => 1,
            ] + $query->getData());
        }
        if ($prepared['redirect_given']) {
            $query->setRedirect($prepared['redirect'] !== '' ? $prepared['redirect'] : null);
        }
        if ($prepared['display_in_terms'] !== null) {
            $query->setDisplayInTerms($prepared['display_in_terms'] ? 1 : 0);
        }
        if ($prepared['popularity'] !== null) {
            $query->setPopularity($prepared['popularity']);
        }
        $this->queryResource->save($query);
    }

    /**
     * @inheritDoc
     */
    protected function remove(array $prepared): void
    {
        /** @var Query $query */
        $query = $this->queryFactory->create();
        $this->queryResource->load($query, (int) $prepared['id']);
        if ($query->getId()) {
            $this->queryResource->delete($query);
        }
    }
}
