<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Profile;

use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Iranimij\OpenImport\Model\I18n\StoredText;

/**
 * Copies a profile (inactive, without secrets) and opens the copy.
 */
class Duplicate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::profiles_edit';

    /**
     * @param Context $context
     * @param ProfileRepositoryInterface $profiles
     * @param ProfileInterfaceFactory $profileFactory
     * @param StoredText $text
     */
    public function __construct(
        Context $context,
        private readonly ProfileRepositoryInterface $profiles,
        private readonly ProfileInterfaceFactory $profileFactory,
        private readonly StoredText $text
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
            $original = $this->profiles->getById((int) $this->getRequest()->getParam('id'));
            $name = (string) __('%1 (copy)', $original->getName());
            for ($i = 2; $this->exists($name); $i++) {
                $name = (string) __('%1 (copy %2)', $original->getName(), $i);
            }
            $source = $original->getSourceConfig();
            unset($source['secrets']);
            $copy = $this->profileFactory->create()
                ->setName($name)
                ->setDirection($original->getDirection())
                ->setEntity($original->getEntity())
                ->setIsActive(false)
                ->setSourceType($original->getSourceType())
                ->setSourceConfig($source)
                ->setFormat($original->getFormat())
                ->setFormatOptions($original->getFormatOptions())
                ->setBehavior($original->getBehavior())
                ->setOnError($original->getOnError())
                ->setAllowedErrors($original->getAllowedErrors())
                ->setDelta($original->isDelta())
                ->setSafetyThreshold($original->getSafetyThreshold())
                ->setSchedule($original->getSchedule())
                ->setAfterSuccess($original->getAfterSuccess())
                ->setNotify($original->getNotify())
                ->setNotifyEmails($original->getNotifyEmails())
                ->setRowFilters($original->getRowFilters())
                ->setExportOptions($original->getExportOptions())
                ->setImportOptions($original->getImportOptions())
                ->setThenProfileId($original->getThenProfileId())
                ->setThenWhen($original->getThenWhen())
                ->setTriggers($original->getTriggers())
                ->setMapping($original->getMapping())
                ->setValueMaps($original->getValueMaps());
            $copy = $this->profiles->save($copy);
            $this->messageManager->addSuccessMessage((string) __('Copied to "%1". Its automatic runs are off until you switch them on.', $name));
            return $redirect->setPath('*/*/edit', ['id' => $copy->getProfileId()]);
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($this->text->translate($exception->getMessage()));
        }

        return $redirect->setPath('*/*/');
    }

    private function exists(string $name): bool
    {
        try {
            $this->profiles->getByName($name);
            return true;
        } catch (LocalizedException $exception) {
            return false;
        }
    }
}
