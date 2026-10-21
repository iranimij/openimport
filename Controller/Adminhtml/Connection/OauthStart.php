<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Connection;

use Iranimij\OpenImport\Api\ConnectionRepositoryInterface;
use Iranimij\OpenImport\Model\Google\OAuthFlow;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * "Sign in with Google": off to Google's consent page.
 */
class OauthStart extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::connections';

    /**
     * @param Context $context
     * @param ConnectionRepositoryInterface $connections
     * @param OAuthFlow $flow
     */
    public function __construct(
        Context $context,
        private readonly ConnectionRepositoryInterface $connections,
        private readonly OAuthFlow $flow
    ) {
        parent::__construct($context);
    }

    /**
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $connectionId = (int) $this->getRequest()->getParam('id');
        try {
            return $redirect->setUrl($this->flow->start($this->connections->getById($connectionId)));
        } catch (PipelineException | NoSuchEntityException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        }

        return $redirect->setPath('*/*/edit', ['id' => $connectionId]);
    }
}
