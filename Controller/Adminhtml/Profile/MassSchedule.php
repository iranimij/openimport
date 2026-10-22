<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Profile;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\ResourceModel\Profile\CollectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\Component\MassAction\Filter;

/**
 * Profiles grid mass actions: switch the automatic runs (schedule and triggers) of the chosen profiles on or off
 * (param "on"), or delete them (param "delete"). Every profile is saved through the repository, so automatic runs
 * that cannot work (an uploaded file, an export without destination) stay off and are named.
 */
class MassSchedule extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::profiles_edit';

    /**
     * @param Context $context
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     * @param ProfileRepositoryInterface $profiles
     */
    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly ProfileRepositoryInterface $profiles
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Redirect
    {
        $delete = (bool) $this->getRequest()->getParam('delete');
        $on = (bool) $this->getRequest()->getParam('on');
        $done = 0;
        $skipped = [];
        try {
            /** @var ProfileInterface $profile */
            foreach ($this->filter->getCollection($this->collectionFactory->create()) as $profile) {
                try {
                    if ($delete) {
                        $this->profiles->delete($profile);
                    } elseif ($on && $profile->getSchedule() === null
                        && ($profile->getTriggers()[ProfileInterface::TRIGGER_EVENTS] ?? []) === []) {
                        $skipped[] = sprintf(
                            '%s: %s',
                            $profile->getName(),
                            __('it has no schedule and runs after no event (step 6).')
                        );
                        continue;
                    } else {
                        $this->profiles->save($profile->setIsActive($on));
                    }
                    $done++;
                } catch (LocalizedException $exception) {
                    $skipped[] = sprintf('%s: %s', $profile->getName(), strtok($exception->getMessage(), "\n"));
                }
            }
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        }
        if ($done > 0) {
            $this->messageManager->addSuccessMessage((string) match (true) {
                $delete => __('%1 profile(s) deleted. Their runs are kept.', $done),
                $on => __('Automatic runs switched on for %1 profile(s).', $done),
                default => __('Automatic runs switched off for %1 profile(s).', $done),
            });
        }
        foreach ($skipped as $problem) {
            $this->messageManager->addErrorMessage($problem);
        }

        return $this->resultRedirectFactory->create()->setPath('*/*/');
    }
}
