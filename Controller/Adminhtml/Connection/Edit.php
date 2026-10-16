<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Connection;

use Iranimij\OpenImport\Api\ConnectionRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page as BackendPage;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Connection form.
 */
class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::connections';

    /**
     * @param Context $context
     * @param ConnectionRepositoryInterface $connections
     */
    public function __construct(
        Context $context,
        private readonly ConnectionRepositoryInterface $connections
    ) {
        parent::__construct($context);
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $connectionId = (int) $this->getRequest()->getParam('id');
        $title = (string) __('New connection');
        if ($connectionId > 0) {
            try {
                $title = $this->connections->getById($connectionId)->getName();
            } catch (NoSuchEntityException $exception) {
                $this->messageManager->addErrorMessage((string) __('This connection no longer exists.'));

                return $this->resultRedirectFactory->create()->setPath('*/*/');
            }
        }
        /** @var BackendPage $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('Iranimij_OpenImport::menu_connections');
        $page->getConfig()->getTitle()->prepend((string) __('OpenImport Connections'));
        $page->getConfig()->getTitle()->prepend($title);

        return $page;
    }
}
