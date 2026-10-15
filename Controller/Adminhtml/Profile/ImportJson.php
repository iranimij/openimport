<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Profile;

use Iranimij\OpenImport\Model\Profile\ProfileJson;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File;

/**
 * Imports profiles from a JSON file (the format of "Download as JSON" and openimport:profile:export): GET shows the
 * upload form, POST imports. Profiles with the same name are updated, secrets stay as they are; nothing is saved
 * when one profile has a problem.
 */
class ImportJson extends Action implements HttpGetActionInterface, HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::profiles_edit';

    /**
     * @param Context $context
     * @param ProfileJson $json
     * @param File $file
     */
    public function __construct(
        Context $context,
        private readonly ProfileJson $json,
        private readonly File $file
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): ResultInterface
    {
        $request = $this->getRequest();
        if (!$request instanceof HttpRequest || !$request->isPost()) {
            /** @var Page $page */
            $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
            $page->setActiveMenu('Iranimij_OpenImport::menu_profiles');
            $page->getConfig()->getTitle()->prepend((string) __('Import profiles from JSON'));

            return $page;
        }
        $redirect = $this->resultRedirectFactory->create();
        $upload = $request->getFiles('file');
        $path = is_array($upload) ? (string) ($upload['tmp_name'] ?? '') : '';
        try {
            if ($path === '' || !$this->file->isExists($path)) {
                throw new LocalizedException(__('Choose a JSON file.'));
            }
            [$created, $updated] = $this->json->save($this->json->prepare($this->file->fileGetContents($path)));
            $this->messageManager->addSuccessMessage((string) __(
                '%1 profile(s) created, %2 updated.',
                $created,
                $updated
            ));

            return $redirect->setPath('*/*/');
        } catch (\InvalidArgumentException | LocalizedException $exception) {
            foreach (explode("\n", $exception->getMessage()) as $problem) {
                $this->messageManager->addErrorMessage($problem);
            }

            return $redirect->setPath('*/*/importJson');
        }
    }
}
