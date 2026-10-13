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
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Backend\Model\View\Result\Page as BackendPage;
use Magento\Framework\View\Result\Page;

/**
 * The profile form (new or existing).
 */
class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::profiles';

    /**
     * @param Context $context
     * @param ProfileRepositoryInterface $profiles
     */
    public function __construct(
        Context $context,
        private readonly ProfileRepositoryInterface $profiles
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): ResultInterface
    {
        $profileId = (int) $this->getRequest()->getParam('id');
        $title = (string) __('New profile');
        if ($profileId > 0) {
            try {
                $title = $this->profiles->getById($profileId)->getName();
            } catch (NoSuchEntityException $exception) {
                $this->messageManager->addErrorMessage((string) __('This profile no longer exists.'));
                return $this->resultRedirectFactory->create()->setPath('*/*/');
            }
        } elseif (!$this->_authorization->isAllowed('Iranimij_OpenImport::profiles_edit')) {
            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }
        /** @var BackendPage $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('Iranimij_OpenImport::menu_profiles');
        $page->getConfig()->getTitle()->prepend((string) __('OpenImport Profiles'));
        $page->getConfig()->getTitle()->prepend($title);

        return $page;
    }
}
