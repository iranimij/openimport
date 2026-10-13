<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Run;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Api\RunRepositoryInterface;
use Iranimij\OpenImport\Model\Admin\RunStarter;
use Iranimij\OpenImport\Model\Pipeline\ImportRunner;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;

/**
 * "Run for real" after a dry run, and the typed confirmation for runs above the safety limit.
 */
class Confirm extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::run';

    /**
     * @param Context $context
     * @param RunRepositoryInterface $runs
     * @param ProfileRepositoryInterface $profiles
     * @param RunStarter $runStarter
     */
    public function __construct(
        Context $context,
        private readonly RunRepositoryInterface $runs,
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
        $runId = (int) $this->getRequest()->getParam('id');
        try {
            $run = $this->runs->getById($runId);
            $profile = $this->profiles->getById((int) $run->getProfileId());
            $confirmed = false;
            if ($this->needsWord($run, $profile)) {
                if (!$this->_authorization->isAllowed('Iranimij_OpenImport::run_destructive')) {
                    throw new LocalizedException(__('Your role may not confirm runs above the safety limit.'));
                }
                $word = $profile->getBehavior() === ProfileInterface::BEHAVIOR_DELETE ? 'DELETE' : 'REPLACE';
                if (trim((string) $this->getRequest()->getParam('confirmation')) !== $word) {
                    throw new LocalizedException(__('Type %1 to confirm.', $word));
                }
                $confirmed = true;
            }
            $live = $this->runStarter->liveFromDryRun($profile, $run, $confirmed);
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
            return $redirect->setPath('*/*/view', ['id' => $runId]);
        }

        return $redirect->setPath('*/*/view', ['id' => $live->getRunId()]);
    }

    private function needsWord(RunInterface $run, ProfileInterface $profile): bool
    {
        return $run->getStatus() === RunInterface::STATUS_NEEDS_CONFIRMATION
            || (in_array($profile->getBehavior(), [ProfileInterface::BEHAVIOR_DELETE, ProfileInterface::BEHAVIOR_REPLACE], true)
                && str_contains($run->getMessage(), ImportRunner::CONFIRMATION_NOTE));
    }
}
