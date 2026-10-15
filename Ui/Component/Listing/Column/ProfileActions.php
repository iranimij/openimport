<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Ui\Component\Listing\Column;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Escaper;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Row actions of the profile grid, each shown only to roles allowed to use it.
 */
class ProfileActions extends Column
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface $urlBuilder
     * @param AuthorizationInterface $authorization
     * @param Escaper $escaper
     * @param ResourceConnection $resource
     * @param Json $json
     * @param TimezoneInterface $timezone
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        private readonly AuthorizationInterface $authorization,
        private readonly Escaper $escaper,
        private readonly ResourceConnection $resource,
        private readonly Json $json,
        private readonly TimezoneInterface $timezone,
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
        $edit = $this->authorization->isAllowed('Iranimij_OpenImport::profiles_edit');
        $run = $this->authorization->isAllowed('Iranimij_OpenImport::run');
        if (!isset($dataSource['data']['items']) || !is_array($dataSource['data']['items'])) {
            return $dataSource;
        }
        $dryRuns = $run ? $this->lastDryRuns(array_map(
            static fn (array $item): int => (int) $item['profile_id'],
            $dataSource['data']['items']
        )) : [];
        // By reference into the array itself: a "?? []" expression would be a copy and the grid would keep raw values.
        foreach ($dataSource['data']['items'] as &$item) {
            $id = (int) $item['profile_id'];
            $name = $this->escaper->escapeHtml((string) $item['name']);
            $actions = [
                'edit' => [
                    'href' => $this->urlBuilder->getUrl('openimport/profile/edit', ['id' => $id]),
                    'label' => __($edit ? 'Edit' : 'View'),
                ],
            ];
            if ($run) {
                $actions['dryrun'] = [
                    'href' => $this->urlBuilder->getUrl('openimport/profile/run', ['id' => $id, 'mode' => 'dry_run']),
                    'label' => __('Dry run'),
                    'post' => true,
                ];
                $actions['run'] = [
                    'href' => $this->urlBuilder->getUrl('openimport/profile/run', ['id' => $id, 'mode' => 'live']),
                    'label' => __('Run now'),
                    'post' => true,
                    'confirm' => [
                        'title' => __('Run "%1" now?', $name),
                        'message' => $this->runMessage($item, $dryRuns[$id] ?? null),
                        'button' => __('Run now'),
                    ],
                ];
            }
            if ($edit) {
                $actions['duplicate'] = [
                    'href' => $this->urlBuilder->getUrl('openimport/profile/duplicate', ['id' => $id]),
                    'label' => __('Duplicate'),
                    'post' => true,
                ];
                $actions['delete'] = [
                    'href' => $this->urlBuilder->getUrl('openimport/profile/delete', ['id' => $id]),
                    'label' => __('Delete'),
                    'post' => true,
                    'confirm' => [
                        'title' => __('Delete "%1"?', $name),
                        'message' => __('The profile will be deleted. Its runs and error reports are kept.'),
                        'button' => __('Delete profile'),
                    ],
                ];
            }
            $item[$this->getData('name')] = $actions;
        }

        return $dataSource;
    }

    /**
     * What "Run now" will do, and how the last dry run went.
     *
     * @param array<string, mixed> $item grid row (the source column already holds its label)
     * @param array<string, string>|null $dryRun status and finished_at of the last dry run
     * @return string HTML
     */
    private function runMessage(array $item, ?array $dryRun): string
    {
        $where = $this->escaper->escapeHtml((string) preg_replace('/^→ /u', '', (string) ($item['source_type'] ?? '')));
        if (($item['direction'] ?? 'import') === 'export') {
            $what = __('The export runs now and goes to: %1.', $where);
        } else {
            $config = $this->decode($item['source_config'] ?? null);
            $file = (string) ($config['file_name'] ?? '');
            $what = ($item['source_type_raw'] ?? $item['source_type'] ?? '') === 'upload' && $file !== ''
                ? __('The run imports the uploaded file "%1" and changes the shop.', $this->escaper->escapeHtml($file))
                : __('The run reads the file from: %1, and changes the shop.', $where);
        }
        if ($dryRun === null) {
            $last = __('There is no dry run yet: "Dry run" first shows what will change.');
        } else {
            $when = $this->escaper->escapeHtml($this->timezone->formatDateTime(
                (string) ($dryRun['finished_at'] ?: $dryRun['created_at']),
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::SHORT
            ));
            $last = match ($dryRun['status']) {
                'success' => __('The last dry run (%1) went through.', $when),
                'queued', 'running' => __('A dry run (%1) is still on its way: wait for its result.', $when),
                'partial' => __('<strong>The last dry run (%1) had errors</strong>: look at it before running for real.', $when),
                default => __('<strong>The last dry run (%1) did not go through</strong>: look at it before running for real.', $when),
            };
        }

        return '<p>' . $what . '</p><p>' . $last . '</p>';
    }

    /**
     * @param int[] $profileIds
     * @return array<int, array<string, string>> profile id => status, finished_at, created_at of its last dry run
     */
    private function lastDryRuns(array $profileIds): array
    {
        if ($profileIds === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('openimport_run');
        $latest = $connection->select()
            ->from($table, ['run_id' => new Expression('MAX(run_id)')])
            ->where('mode = ?', 'dry_run')
            ->where('profile_id IN (?)', $profileIds)
            ->group('profile_id');
        $rows = $connection->fetchAll($connection->select()
            ->from(['r' => $table], ['profile_id', 'status', 'finished_at', 'created_at'])
            ->join(['l' => $latest], 'l.run_id = r.run_id', []));
        $runs = [];
        foreach ($rows as $row) {
            $runs[(int) $row['profile_id']] = array_map('strval', $row);
        }

        return $runs;
    }

    /**
     * @param mixed $value JSON column or already decoded
     * @return array<string, mixed>
     */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        try {
            $decoded = $this->json->unserialize((string) $value);
        } catch (\InvalidArgumentException $exception) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
