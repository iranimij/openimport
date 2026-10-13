<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Run;

use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Model\ResourceModel\Run as RunResource;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;

/**
 * Cancels a run that has not started yet.
 */
class Cancel extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::run';

    /**
     * @param Context $context
     * @param RunResource $runResource
     */
    public function __construct(
        Context $context,
        private readonly RunResource $runResource
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Redirect
    {
        $runId = (int) $this->getRequest()->getParam('id');
        if ($this->runResource->cancelQueued($runId)) {
            $this->messageManager->addSuccessMessage((string) __('The run was cancelled.'));
        } else {
            $this->messageManager->addErrorMessage((string) __('Only runs that have not started yet can be cancelled.'));
        }

        return $this->resultRedirectFactory->create()->setPath('*/*/view', ['id' => $runId]);
    }
}
