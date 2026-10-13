<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Profile;

use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Admin\RunStarter;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;

/**
 * "Run now" / "Dry run": queues the run (OpenImport's cron runs it within a minute) and opens its page.
 */
class Run extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::run';

    /**
     * @param Context $context
     * @param ProfileRepositoryInterface $profiles
     * @param RunStarter $runStarter
     */
    public function __construct(
        Context $context,
        private readonly ProfileRepositoryInterface $profiles,
        private readonly RunStarter $runStarter
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        try {
            $profile = $this->profiles->getById((int) $this->getRequest()->getParam('id'));
            $mode = $this->getRequest()->getParam('mode') === RunInterface::MODE_LIVE
                ? RunInterface::MODE_LIVE
                : RunInterface::MODE_DRY_RUN;
            $options = $this->getRequest()->getParam('full') ? ['full' => true] : [];
            $run = $this->runStarter->start($profile, $mode, $options);
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
            return $redirect->setRefererOrBaseUrl();
        }

        return $redirect->setPath('*/run/view', ['id' => $run->getRunId()]);
    }
}
