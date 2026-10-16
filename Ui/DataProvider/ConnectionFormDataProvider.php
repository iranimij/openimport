<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Ui\DataProvider;

use Iranimij\OpenImport\Api\ConnectionRepositoryInterface;
use Iranimij\OpenImport\Controller\Adminhtml\Connection\Save;
use Iranimij\OpenImport\Model\Admin\ConnectionFormMapper;
use Iranimij\OpenImport\Model\Connection\ConnectionUsage;
use Iranimij\OpenImport\Model\Google\OAuthFlow;
use Iranimij\OpenImport\Model\ConnectionFactory;
use Iranimij\OpenImport\Model\ResourceModel\Connection\CollectionFactory;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Ui\DataProvider\AbstractDataProvider;

/**
 * Data of the connection form: the connection without its secrets, which profiles use it, or what was entered
 * before a failed save.
 */
class ConnectionFormDataProvider extends AbstractDataProvider
{
    /**
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param CollectionFactory $collectionFactory
     * @param ConnectionRepositoryInterface $connections
     * @param ConnectionFactory $connectionFactory
     * @param ConnectionFormMapper $mapper
     * @param ConnectionUsage $usage
     * @param DataPersistorInterface $dataPersistor
     * @param RequestInterface $request
     * @param OAuthFlow $oauth
     * @param UrlInterface $url
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $data
     */
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly ConnectionRepositoryInterface $connections,
        private readonly ConnectionFactory $connectionFactory,
        private readonly ConnectionFormMapper $mapper,
        private readonly ConnectionUsage $usage,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly RequestInterface $request,
        private readonly OAuthFlow $oauth,
        private readonly UrlInterface $url,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function getData(): array
    {
        $connectionId = (int) $this->request->getParam('id');
        $connection = $this->connectionFactory->create();
        if ($connectionId > 0) {
            try {
                $connection = $this->connections->getById($connectionId);
            } catch (NoSuchEntityException $exception) {
                return [];
            }
        }
        $data = $this->mapper->toForm($connection);
        $posted = $this->dataPersistor->get(Save::PERSIST_KEY);
        if (is_array($posted)) {
            $this->dataPersistor->clear(Save::PERSIST_KEY);
            $data['name'] = (string) ($posted['name'] ?? '');
            $data['type'] = (string) ($posted['type'] ?? $data['type']);
            $data['settings'] = (is_array($posted['settings'] ?? null) ? $posted['settings'] : []) + $data['settings'];
        }
        $data['redirect_uri'] = $this->oauth->redirectUri();
        $account = (string) ($data['settings']['account'] ?? '');
        $data['service_account_note'] = $account !== '' && ($data['settings']['auth_google'] ?? '') !== 'oauth'
            ? $account
            : '';
        $data['google_signin_url'] = $connectionId > 0 && $connection->getType() === 'google_drive'
            ? $this->url->getUrl('openimport/connection/oauthStart', ['id' => $connectionId])
            : '';
        $profiles = $connectionId > 0 ? $this->usage->profiles($connectionId) : [];
        $data['used_by'] = $profiles === []
            ? (string) __('No profile uses this connection yet.')
            : (string) __('Used by: %1', implode(', ', $profiles));

        return [$connectionId > 0 ? $connectionId : '' => $data];
    }
}
