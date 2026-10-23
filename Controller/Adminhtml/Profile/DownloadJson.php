<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Profile;

use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Profile\ProfileJson;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Iranimij\OpenImport\Model\I18n\StoredText;

/**
 * Downloads a profile as JSON (the format of openimport:profile:export), without passwords, keys or tokens.
 */
class DownloadJson extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::profiles';

    /**
     * @param Context $context
     * @param ProfileRepositoryInterface $profiles
     * @param ProfileJson $json
     * @param StoredText $text
     */
    public function __construct(
        Context $context,
        private readonly ProfileRepositoryInterface $profiles,
        private readonly ProfileJson $json,
        private readonly StoredText $text
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Raw|Redirect
    {
        try {
            $profile = $this->profiles->getById((int) $this->getRequest()->getParam('id'));
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($profile->getName())), '-');

            $name = 'openimport-profile-' . ($slug !== '' ? $slug : $profile->getProfileId()) . '.json';
            /** @var Raw $result */
            $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
            $result->setHeader('Content-Type', 'application/json; charset=utf-8', true)
                ->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"', true)
                ->setContents($this->json->encode([$profile]));

            return $result;
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($this->text->translate($exception->getMessage()));
            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }
    }
}
