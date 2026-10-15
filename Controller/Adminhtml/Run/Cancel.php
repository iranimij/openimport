<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Run;

use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Model\Execution\RunFiles;
use Iranimij\OpenImport\Model\ResourceModel\Run as RunResource;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;

/**
 * Cancels a run: a queued one at once, a running one at its next checkpoint while it still reads (before it writes
 * to the shop or sends a file).
 */
class Cancel extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::run';

    /**
     * @param Context $context
     * @param RunResource $runResource
     * @param RunFiles $runFiles
     */
    public function __construct(
        Context $context,
        private readonly RunResource $runResource,
        private readonly RunFiles $runFiles
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
        } elseif ($this->runResource->fetchStatus($runId) === RunInterface::STATUS_RUNNING) {
            $this->runFiles->requestCancel($runId);
            $this->messageManager->addSuccessMessage((string) __(
                'The run stops within seconds while it still reads the file; once it writes, it finishes that step.'
            ));
        } else {
            $this->messageManager->addErrorMessage((string) __('This run has already finished.'));
        }

        // From the runs grid back to the grid, from the run page back to the run.
        return $this->getRequest()->getParam('back') === 'grid'
            ? $this->resultRedirectFactory->create()->setPath('*/*/index')
            : $this->resultRedirectFactory->create()->setPath('*/*/view', ['id' => $runId]);
    }
}
