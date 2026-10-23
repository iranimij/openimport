<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Profile;

use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Iranimij\OpenImport\Model\I18n\StoredText;

/**
 * Deletes a profile; its runs stay with the profile name.
 */
class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::profiles_edit';

    /**
     * @param Context $context
     * @param ProfileRepositoryInterface $profiles
     * @param StoredText $text
     */
    public function __construct(
        Context $context,
        private readonly ProfileRepositoryInterface $profiles,
        private readonly StoredText $text
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Redirect
    {
        try {
            $profile = $this->profiles->getById((int) $this->getRequest()->getParam('id'));
            $this->profiles->delete($profile);
            $this->messageManager->addSuccessMessage((string) __('The profile "%1" was deleted. Its runs are kept.', $profile->getName()));
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($this->text->translate($exception->getMessage()));
        }

        return $this->resultRedirectFactory->create()->setPath('*/*/');
    }
}
