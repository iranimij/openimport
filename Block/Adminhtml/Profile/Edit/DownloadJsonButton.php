<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Block\Adminhtml\Profile\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * "Download as JSON": the saved profile in the format of openimport:profile:export, without secrets.
 */
class DownloadJsonButton extends GenericButton implements ButtonProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        if ($this->profileId() === 0) {
            return [];
        }

        return [
            'label' => __('Download as JSON'),
            'class' => 'action-secondary',
            'on_click' => sprintf("setLocation('%s')", $this->url('*/*/downloadJson', ['id' => $this->profileId()])),
            'sort_order' => 15,
        ];
    }
}
