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
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;

/**
 * Download: the file a run read (or produced). Only paths inside the run folder are served.
 */
class Download extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::run_files';

    /**
     * @param Context $context
     * @param RunRepositoryInterface $runs
     * @param FileFactory $fileFactory
     * @param Filesystem $filesystem
     */
    public function __construct(
        Context $context,
        private readonly RunRepositoryInterface $runs,
        private readonly FileFactory $fileFactory,
        private readonly Filesystem $filesystem
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
            $file = $this->getRequest()->getParam('file') === 'output' ? $run->getOutputFile() : $run->getSourceFile();
            $expected = 'openimport/runs/' . (int) $run->getRunId() . '/';
            if ($file === null || !str_starts_with($file, $expected) || str_contains($file, '..')
                || !$this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR)->isFile($file)) {
                throw new LocalizedException(__('The file of this run is no longer kept.'));
            }
            $name = $this->getRequest()->getParam('file') === 'output'
                ? (new \SplFileInfo($file))->getFilename()
                : ($run->getOptions()['original_name'] ?? (new \SplFileInfo($file))->getFilename());

            return $this->fileFactory->create(
                (string) $name,
                ['type' => 'filename', 'value' => $file, 'rm' => false],
                DirectoryList::VAR_DIR
            );
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }
    }
}
