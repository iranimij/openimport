<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Ui\Component\Listing\Column;

use Iranimij\OpenImport\Api\Data\RunInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Row action of the run grid: open the run page.
 */
class RunActions extends Column
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface $urlBuilder
     * @param AuthorizationInterface $authorization
     * @param Escaper $escaper
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        private readonly AuthorizationInterface $authorization,
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
        if (!isset($dataSource['data']['items']) || !is_array($dataSource['data']['items'])) {
            return $dataSource;
        }
        // By reference into the array itself: a "?? []" expression would be a copy and the grid would keep raw values.
        $run = $this->authorization->isAllowed('Iranimij_OpenImport::run');
        foreach ($dataSource['data']['items'] as &$item) {
            $id = (int) $item['run_id'];
            $status = (string) ($item['status_raw'] ?? $item['status'] ?? '');
            $actions = [
                'view' => [
                    'href' => $this->urlBuilder->getUrl('openimport/run/view', ['id' => $id]),
                    'label' => __('Open'),
                ],
            ];
            $name = $this->escaper->escapeHtml((string) ($item['profile_name'] ?? ''));
            if ($run && in_array($status, [RunInterface::STATUS_QUEUED, RunInterface::STATUS_RUNNING], true)) {
                $actions['cancel'] = [
                    'href' => $this->urlBuilder->getUrl('openimport/run/cancel', ['id' => $id, 'back' => 'grid']),
                    'label' => __('Cancel'),
                    'post' => true,
                    'confirm' => [
                        'title' => __('Cancel run #%1?', $id),
                        'message' => __('A run that has not started is cancelled at once; a running one stops while it still reads the file.'),
                        'button' => __('Cancel the run'),
                    ],
                ];
            }
            if ($run && !empty($item['profile_id']) && in_array($status, RunInterface::FINAL_STATUSES, true)) {
                $dry = ($item['mode'] ?? '') !== RunInterface::MODE_LIVE;
                $actions['again'] = [
                    'href' => $this->urlBuilder->getUrl('openimport/profile/run', [
                        'id' => (int) $item['profile_id'],
                        'mode' => $dry ? RunInterface::MODE_DRY_RUN : RunInterface::MODE_LIVE,
                    ]),
                    'label' => $dry ? __('Dry run again') : __('Run again'),
                    'post' => true,
                ] + ($dry ? [] : ['confirm' => [
                    'title' => __('Run "%1" again?', $name),
                    'message' => __('The run reads the source again and changes the shop.'),
                    'button' => __('Run again'),
                ]]);
            }
            $item[$this->getData('name')] = $actions;
        }

        return $dataSource;
    }
}
