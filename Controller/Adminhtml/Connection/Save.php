<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Connection;

use Iranimij\OpenImport\Api\ConnectionRepositoryInterface;
use Iranimij\OpenImport\Model\Admin\ConnectionFormMapper;
use Iranimij\OpenImport\Model\ConnectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;

/**
 * Saves a connection; problems go back to the form with what was entered (never the secrets).
 */
class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::connections';
    public const PERSIST_KEY = 'openimport_connection';

    /**
     * @param Context $context
     * @param ConnectionRepositoryInterface $connections
     * @param ConnectionFactory $connectionFactory
     * @param ConnectionFormMapper $mapper
     * @param DataPersistorInterface $dataPersistor
     */
    public function __construct(
        Context $context,
        private readonly ConnectionRepositoryInterface $connections,
        private readonly ConnectionFactory $connectionFactory,
        private readonly ConnectionFormMapper $mapper,
        private readonly DataPersistorInterface $dataPersistor
    ) {
        parent::__construct($context);
    }

    /**
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $request = $this->getRequest();
        $data = $request instanceof HttpRequest ? (array) $request->getPostValue() : [];
        $connectionId = (int) ($data['connection_id'] ?? 0);
        try {
            $connection = $connectionId > 0
                ? $this->connections->getById($connectionId)
                : $this->connectionFactory->create();
            $connection = $this->connections->save($this->mapper->fromForm($data, $connection));
            $this->dataPersistor->clear(self::PERSIST_KEY);
        } catch (LocalizedException $exception) {
            foreach (explode("\n", $exception->getMessage()) as $problem) {
                $this->messageManager->addErrorMessage($problem);
            }
            $this->dataPersistor->set(self::PERSIST_KEY, $this->withoutSecrets($data));

            return $connectionId > 0
                ? $redirect->setPath('*/*/edit', ['id' => $connectionId])
                : $redirect->setPath('*/*/new');
        }
        $this->messageManager->addSuccessMessage(
            (string) __('The connection "%1" was saved.', $connection->getName())
        );
        if ($this->getRequest()->getParam('back') !== null) {
            return $redirect->setPath('*/*/edit', ['id' => $connection->getConnectionId()]);
        }

        return $redirect->setPath('*/*/');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function withoutSecrets(array $data): array
    {
        if (is_array($data['settings'] ?? null)) {
            foreach (['password', 'private_key', 'passphrase', 'token', 'secrets'] as $name) {
                unset($data['settings'][$name]);
            }
        }

        return $data;
    }
}
