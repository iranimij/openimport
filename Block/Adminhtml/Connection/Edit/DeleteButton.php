<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Block\Adminhtml\Connection\Edit;

use Iranimij\OpenImport\Block\Adminhtml\Profile\Edit\GenericButton;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * Delete on the connection form.
 */
class DeleteButton extends GenericButton implements ButtonProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        $id = (int) $this->context->getRequest()->getParam('id');
        if ($id === 0) {
            return [];
        }

        return [
            'label' => __('Delete'),
            'class' => 'delete',
            'on_click' => sprintf(
                "deleteConfirm('%s', '%s', {data: {}})",
                __('Delete this connection? Profiles that use it must choose another login first.'),
                $this->url('*/*/delete', ['id' => $id])
            ),
            'sort_order' => 20,
        ];
    }
}
