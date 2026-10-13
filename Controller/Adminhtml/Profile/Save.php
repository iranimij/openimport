<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Profile;

use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Admin\ProfileFormMapper;
use Iranimij\OpenImport\Model\Admin\RunStarter;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;

/**
 * Saves the profile form; "Save and dry run" queues a dry run and opens its page.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::profiles_edit';
    public const PERSIST_KEY = 'openimport_profile';

    /**
     * @param Context $context
     * @param ProfileRepositoryInterface $profiles
     * @param ProfileInterfaceFactory $profileFactory
     * @param ProfileFormMapper $mapper
     * @param DataPersistorInterface $dataPersistor
     * @param RunStarter $runStarter
     * @param Session $authSession
     */
    public function __construct(
        Context $context,
        private readonly ProfileRepositoryInterface $profiles,
        private readonly ProfileInterfaceFactory $profileFactory,
        private readonly ProfileFormMapper $mapper,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly RunStarter $runStarter,
        private readonly Session $authSession
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $request = $this->getRequest();
        $data = $request instanceof HttpRequest ? (array) $request->getPostValue() : [];
        $profileId = (int) ($data['profile_id'] ?? 0);
        try {
            $profile = $profileId > 0 ? $this->profiles->getById($profileId) : $this->profileFactory->create();
            if ($profileId === 0) {
                $profile->setCreatedBy((string) $this->authSession->getUser()?->getUserName());
            }
            $profile = $this->profiles->save($this->mapper->fromForm($data, $profile));
            $this->dataPersistor->clear(self::PERSIST_KEY);
        } catch (LocalizedException $exception) {
            foreach (explode("\n", $exception->getMessage()) as $problem) {
                $this->messageManager->addErrorMessage($problem);
            }
            $this->dataPersistor->set(self::PERSIST_KEY, $data);
            return $profileId > 0
                ? $redirect->setPath('*/*/edit', ['id' => $profileId])
                : $redirect->setPath('*/*/new');
        }
        $this->messageManager->addSuccessMessage((string) __('The profile "%1" was saved.', $profile->getName()));
        if ($this->getRequest()->getParam('back') === 'dryrun') {
            try {
                $run = $this->runStarter->start($profile, RunInterface::MODE_DRY_RUN);
                return $redirect->setPath('*/run/view', ['id' => $run->getRunId()]);
            } catch (LocalizedException $exception) {
                $this->messageManager->addErrorMessage($exception->getMessage());
            }
        }
        if ($this->getRequest()->getParam('back') !== null) {
            return $redirect->setPath('*/*/edit', ['id' => $profile->getProfileId()]);
        }

        return $redirect->setPath('*/*/');
    }
}
