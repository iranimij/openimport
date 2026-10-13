<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Run;

use Iranimij\OpenImport\Api\RunRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Backend\Model\View\Result\Page as BackendPage;
use Magento\Framework\View\Result\Page;

/**
 * The run page: status, counts, change preview, errors, downloads.
 */
class View extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::runs';

    /**
     * @param Context $context
     * @param RunRepositoryInterface $runs
     */
    public function __construct(
        Context $context,
        private readonly RunRepositoryInterface $runs
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): ResultInterface
    {
        try {
            $run = $this->runs->getById((int) $this->getRequest()->getParam('id'));
        } catch (NoSuchEntityException $exception) {
            $this->messageManager->addErrorMessage((string) __('This run does not exist.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }
        /** @var BackendPage $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('Iranimij_OpenImport::menu_runs');
        $page->getConfig()->getTitle()->prepend((string) __('OpenImport Runs'));
        $page->getConfig()->getTitle()->prepend((string) __(
            '%1 #%2 · %3',
            $run->isDryRun() ? __('Dry run') : __('Run'),
            (int) $run->getRunId(),
            $run->getProfileName()
        ));

        return $page;
    }
}
