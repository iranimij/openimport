<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Ui\Component\Listing\Column;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Escaper;
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
        $edit = $this->authorization->isAllowed('Iranimij_OpenImport::profiles_edit');
        $run = $this->authorization->isAllowed('Iranimij_OpenImport::run');
        if (!isset($dataSource['data']['items']) || !is_array($dataSource['data']['items'])) {
            return $dataSource;
        }
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
                        'message' => __('The run changes the shop. A dry run first shows what will change.'),
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
                        'message' => __('The profile is deleted. Its runs and error reports are kept.'),
                    ],
                ];
            }
            $item[$this->getData('name')] = $actions;
        }

        return $dataSource;
    }
}
