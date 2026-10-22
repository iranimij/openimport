<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Block\Adminhtml\Profile\Edit;

use Iranimij\OpenImport\Model\Chain\ChainLinks;
use Magento\Backend\Block\Widget\Context;
use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * Delete (existing profiles, editors only).
 */
class DeleteButton extends GenericButton implements ButtonProviderInterface
{
    /**
     * @param Context $context
     * @param ChainLinks $chains
     * @param Escaper $escaper
     */
    public function __construct(
        Context $context,
        private readonly ChainLinks $chains,
        private readonly Escaper $escaper
    ) {
        parent::__construct($context);
    }

    /**
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        if ($this->profileId() === 0 || !$this->allowed('Iranimij_OpenImport::profiles_edit')) {
            return [];
        }

        $message = (string) __('Delete this profile? Its runs and error reports are kept.');
        $names = $this->chains->predecessors($this->profileId());
        if ($names !== []) {
            $message .= ' ' . __('"%1" run(s) this profile next; their chain will stop there.', implode('", "', $names));
        }

        return [
            'label' => __('Delete'),
            'class' => 'delete',
            'on_click' => sprintf(
                "deleteConfirm('%s', '%s', {data: {}})",
                $this->escaper->escapeJs($this->escaper->escapeHtml($message)),
                $this->url('*/*/delete', ['id' => $this->profileId()])
            ),
            'sort_order' => 20,
        ];
    }
}
