<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Block\Adminhtml\Profile\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * Delete (existing profiles, editors only).
 */
class DeleteButton extends GenericButton implements ButtonProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        if ($this->profileId() === 0 || !$this->allowed('Iranimij_OpenImport::profiles_edit')) {
            return [];
        }

        return [
            'label' => __('Delete'),
            'class' => 'delete',
            'on_click' => sprintf(
                "deleteConfirm('%s', '%s', {data: {}})",
                __('Delete this profile? Its runs and error reports are kept.'),
                $this->url('*/*/delete', ['id' => $this->profileId()])
            ),
            'sort_order' => 20,
        ];
    }
}
