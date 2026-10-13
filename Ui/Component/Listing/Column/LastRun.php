<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Ui\Component\Listing\Column;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Last run of each profile on the page (one query for the whole page).
 */
class LastRun extends Column
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param ResourceConnection $resource
     * @param StatusChip $chip
     * @param UrlInterface $urlBuilder
     * @param Escaper $escaper
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly ResourceConnection $resource,
        private readonly StatusChip $chip,
        private readonly UrlInterface $urlBuilder,
        private readonly Escaper $escaper,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @param array<string, mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource)
    {
        $items = &$dataSource['data']['items'];
        if (empty($items)) {
            return $dataSource;
        }
        $ids = array_map(static fn (array $item): int => (int) $item['profile_id'], $items);
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('openimport_run');
        $latest = $connection->select()
            ->from($table, ['run_id' => new \Zend_Db_Expr('MAX(run_id)')])
            ->where('profile_id IN (?)', $ids)
            ->group('profile_id');
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['run_id', 'profile_id', 'status', 'mode', 'created_at'])
                ->where('run_id IN (?)', $latest)
        );
        $byProfile = [];
        foreach ($rows as $row) {
            $byProfile[(int) $row['profile_id']] = $row;
        }
        foreach ($items as &$item) {
            $run = $byProfile[(int) $item['profile_id']] ?? null;
            $item[$this->getData('name')] = $run === null
                ? $this->escaper->escapeHtml((string) __('Never run'))
                : sprintf(
                    '<a href="%s">%s</a> %s',
                    $this->escaper->escapeUrl($this->urlBuilder->getUrl('openimport/run/view', ['id' => $run['run_id']])),
                    $this->escaper->escapeHtml((string) __($run['mode'] === 'live' ? 'Run #%1' : 'Dry run #%1', $run['run_id'])),
                    $this->chip->html((string) $run['status'])
                );
        }

        return $dataSource;
    }
}
