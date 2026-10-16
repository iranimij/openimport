<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Connection;

use Iranimij\OpenImport\Api\ConnectionRepositoryInterface;
use Iranimij\OpenImport\Model\Admin\ConnectionFormMapper;
use Iranimij\OpenImport\Model\Connection\ConnectionTester;
use Iranimij\OpenImport\Model\Connection\ConnectionValidator;
use Iranimij\OpenImport\Model\ConnectionFactory;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * "Test connection" with what the form holds now (stored secrets for empty secret fields); nothing is saved.
 */
class Test extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::connections';

    /**
     * @param Context $context
     * @param ConnectionRepositoryInterface $connections
     * @param ConnectionFactory $connectionFactory
     * @param ConnectionFormMapper $mapper
     * @param ConnectionValidator $validator
     * @param ConnectionTester $tester
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        private readonly ConnectionRepositoryInterface $connections,
        private readonly ConnectionFactory $connectionFactory,
        private readonly ConnectionFormMapper $mapper,
        private readonly ConnectionValidator $validator,
        private readonly ConnectionTester $tester,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    /**
     * @return Json
     */
    public function execute(): Json
    {
        /** @var Json $json */
        $json = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $request = $this->getRequest();
        $data = $request instanceof HttpRequest ? (array) $request->getPostValue() : [];
        $connectionId = (int) ($data['connection_id'] ?? 0);
        $connection = $this->connectionFactory->create();
        if ($connectionId > 0) {
            try {
                $connection = $this->connections->getById($connectionId);
            } catch (NoSuchEntityException $exception) {
                $connection = $this->connectionFactory->create();
            }
        }
        // A name is not needed to test.
        $data['name'] = (string) ($data['name'] ?? '') ?: 'test';
        $connection = $this->mapper->fromForm($data, $connection);
        $problems = $this->validator->validate($connection);
        if ($problems !== []) {
            return $json->setData(['ok' => false, 'message' => implode(' ', $problems)]);
        }
        try {
            $message = $this->tester->test($connection);
        } catch (PipelineException $exception) {
            return $json->setData(['ok' => false, 'message' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            $this->logger->error(
                'OpenImport test connection: ' . $exception->getMessage(),
                ['exception' => $exception]
            );

            return $json->setData([
                'ok' => false,
                'message' => (string) __('The test failed unexpectedly: %1', $exception->getMessage()),
            ]);
        }

        return $json->setData(['ok' => true, 'message' => $message]);
    }
}
