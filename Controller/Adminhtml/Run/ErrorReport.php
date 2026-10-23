<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Run;

use Iranimij\OpenImport\Api\RunRepositoryInterface;
use Iranimij\OpenImport\Model\Admin\ErrorReport as Report;
use Iranimij\OpenImport\Model\Execution\RunFiles;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Iranimij\OpenImport\Model\I18n\StoredText;

/**
 * Download: the run's errors with the original rows (CSV); without the rows' cells for users who may not download
 * run files.
 */
class ErrorReport extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::runs';

    /**
     * @param Context $context
     * @param RunRepositoryInterface $runs
     * @param Report $report
     * @param RunFiles $runFiles
     * @param FileFactory $fileFactory
     * @param StoredText $text
     */
    public function __construct(
        Context $context,
        private readonly RunRepositoryInterface $runs,
        private readonly Report $report,
        private readonly RunFiles $runFiles,
        private readonly FileFactory $fileFactory,
        private readonly StoredText $text
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): ResponseInterface|ResultInterface
    {
        try {
            $run = $this->runs->getById((int) $this->getRequest()->getParam('id'));
            // The source cells are file contents: only with "Download run files".
            $path = $this->report->write($run, $this->_authorization->isAllowed(Download::ADMIN_RESOURCE));

            return $this->fileFactory->create(
                sprintf('openimport-run-%d-errors.csv', (int) $run->getRunId()),
                ['type' => 'filename', 'value' => $this->runFiles->relative($path), 'rm' => false],
                DirectoryList::VAR_DIR,
                'text/csv'
            );
        } catch (LocalizedException | \RuntimeException $exception) {
            $this->messageManager->addErrorMessage($this->text->translate($exception->getMessage()));
            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }
    }
}
