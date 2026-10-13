<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Block\Adminhtml\Profile\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * Save (stay on the form).
 */
class SaveButton extends GenericButton implements ButtonProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        if (!$this->allowed('Iranimij_OpenImport::profiles_edit')) {
            return [];
        }

        return [
            'label' => __('Save'),
            'class' => 'save',
            'data_attribute' => [
                'mage-init' => [
                    'buttonAdapter' => [
                        'actions' => [[
                            'targetName' => 'openimport_profile_form.openimport_profile_form',
                            'actionName' => 'save',
                            'params' => [true, ['back' => 'edit']],
                        ]],
                    ],
                ],
            ],
            'sort_order' => 30,
        ];
    }
}
