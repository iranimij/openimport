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
 * Save on the connection form.
 */
class SaveButton extends GenericButton implements ButtonProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        return [
            'label' => __('Save'),
            'class' => 'save primary',
            'data_attribute' => [
                'mage-init' => [
                    'buttonAdapter' => [
                        'actions' => [[
                            'targetName' => 'openimport_connection_form.openimport_connection_form',
                            'actionName' => 'save',
                            'params' => [true],
                        ]],
                    ],
                ],
            ],
            'sort_order' => 30,
        ];
    }
}
