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
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Iranimij\OpenImport\Model\I18n\StoredText;

/**
 * Deletes a connection no profile uses.
 */
class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::connections';

    /**
     * @param Context $context
     * @param ConnectionRepositoryInterface $connections
     * @param StoredText $text
     */
    public function __construct(
        Context $context,
        private readonly ConnectionRepositoryInterface $connections,
        private readonly StoredText $text
    ) {
        parent::__construct($context);
    }

    /**
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/');
        try {
            $connection = $this->connections->getById((int) $this->getRequest()->getParam('id'));
            $this->connections->delete($connection);
            $this->messageManager->addSuccessMessage(
                (string) __('The connection "%1" was deleted.', $connection->getName())
            );
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($this->text->translate($exception->getMessage()));
        }

        return $redirect;
    }
}
