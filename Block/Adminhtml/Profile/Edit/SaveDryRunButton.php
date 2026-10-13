<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Block\Adminhtml\Profile\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * Save and dry run (the primary action: see what a run would change before it changes anything).
 */
class SaveDryRunButton extends GenericButton implements ButtonProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        if (!$this->allowed('Iranimij_OpenImport::profiles_edit') || !$this->allowed('Iranimij_OpenImport::run')) {
            return [];
        }

        return [
            'label' => __('Save and dry run'),
            'class' => 'save primary',
            'data_attribute' => [
                'mage-init' => [
                    'buttonAdapter' => [
                        'actions' => [[
                            'targetName' => 'openimport_profile_form.openimport_profile_form',
                            'actionName' => 'save',
                            'params' => [true, ['back' => 'dryrun']],
                        ]],
                    ],
                ],
                'form-role' => 'save',
            ],
            'sort_order' => 40,
        ];
    }
}
