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
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Light status endpoint for the run page while a run is queued or running (one row read).
 */
class Status extends Action implements HttpGetActionInterface
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
    public function execute(): Json
    {
        /** @var Json $json */
        $json = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        try {
            $run = $this->runs->getById((int) $this->getRequest()->getParam('id'));
        } catch (NoSuchEntityException $exception) {
            return $json->setData(['status' => 'missing']);
        }

        return $json->setData([
            'status' => $run->getStatus(),
            'progress' => $run->getProgressRows(),
            'counts' => $run->getCounts(),
        ]);
    }
}
