<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Connection;

use Iranimij\OpenImport\Model\Google\OAuthFlow;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;

/**
 * Google sends the admin back here after the consent page. The address has no secret key (Google needs a fixed
 * redirect URI); the admin must still be logged in and allowed to manage connections, and the state must be one this
 * session started.
 */
class OauthCallback extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::connections';

    /**
     * @var string[]
     */
    protected $_publicActions = ['oauthcallback'];

    /**
     * @param Context $context
     * @param OAuthFlow $flow
     */
    public function __construct(
        Context $context,
        private readonly OAuthFlow $flow
    ) {
        parent::__construct($context);
    }

    /**
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $request = $this->getRequest();
        $error = (string) $request->getParam('error');
        if ($error !== '') {
            $this->messageManager->addErrorMessage((string) __('Google did not sign in: %1', $error));

            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }
        try {
            $connection = $this->flow->finish(
                (string) $request->getParam('state'),
                (string) $request->getParam('code')
            );
        } catch (PipelineException | LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());

            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }
        $account = (string) ($connection->getSettings()['account'] ?? '');
        $this->messageManager->addSuccessMessage($account !== ''
            ? (string) __('"%1" is signed in to Google as %2.', $connection->getName(), $account)
            : (string) __('"%1" is signed in to Google.', $connection->getName()));

        return $this->resultRedirectFactory->create()->setPath('*/*/edit', ['id' => $connection->getConnectionId()]);
    }
}
