<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Profile;

use Iranimij\OpenImport\Model\Preset\PresetRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Iranimij\OpenImport\Model\I18n\StoredText;

/**
 * Creates an inactive profile from a preset (Shopify, WooCommerce, Magento 1 files) and opens it.
 */
class FromPreset extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::profiles_edit';

    /**
     * @param Context $context
     * @param PresetRepository $presets
     * @param StoredText $text
     */
    public function __construct(
        Context $context,
        private readonly PresetRepository $presets,
        private readonly StoredText $text
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $code = (string) $this->getRequest()->getParam('preset');
        try {
            $profile = $this->presets->create($code);
        } catch (\InvalidArgumentException $exception) {
            foreach (explode("\n", $this->text->translate($exception->getMessage())) as $problem) {
                $this->messageManager->addErrorMessage($problem);
            }

            return $redirect->setPath('*/*/importJson');
        }
        $notes = $this->presets->all()[$code]['notes'] ?? '';
        $this->messageManager->addSuccessMessage((string) __(
            'Profile "%1" created from the preset. Upload a file in step 2, check the mapping, then run a dry run.',
            $profile->getName()
        ));
        if ($notes !== '') {
            $this->messageManager->addNoticeMessage((string) __($notes));
        }

        return $redirect->setPath('*/*/edit', ['id' => $profile->getProfileId()]);
    }
}
