<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Ui\DataProvider;

use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Controller\Adminhtml\Profile\Save;
use Iranimij\OpenImport\Model\Admin\ProfileFormMapper;
use Iranimij\OpenImport\Model\Config;
use Iranimij\OpenImport\Model\ResourceModel\Profile\CollectionFactory;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Ui\DataProvider\AbstractDataProvider;

/**
 * Data of the profile form: the saved profile, or what was posted when saving failed.
 */
class ProfileFormDataProvider extends AbstractDataProvider
{
    /**
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param CollectionFactory $collectionFactory
     * @param ProfileRepositoryInterface $profiles
     * @param ProfileInterfaceFactory $profileFactory
     * @param ProfileFormMapper $mapper
     * @param DataPersistorInterface $dataPersistor
     * @param RequestInterface $request
     * @param AuthorizationInterface $authorization
     * @param Config $config
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $data
     */
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly ProfileRepositoryInterface $profiles,
        private readonly ProfileInterfaceFactory $profileFactory,
        private readonly ProfileFormMapper $mapper,
        private readonly DataPersistorInterface $dataPersistor,
        private readonly RequestInterface $request,
        private readonly AuthorizationInterface $authorization,
        private readonly Config $config,
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
        $profileId = (int) $this->request->getParam('id');
        $posted = $this->dataPersistor->get(Save::PERSIST_KEY);
        if (is_array($posted)) {
            $this->dataPersistor->clear(Save::PERSIST_KEY);
            $profile = $this->mapper->fromForm($posted, $this->profileFactory->create());
            $data = $this->mapper->toForm($profile);
            $data['profile_id'] = $profileId > 0 ? $profileId : null;
            $data['source_config'] = is_array($posted['source_config'] ?? null) ? $posted['source_config'] : [];
            return [$profileId > 0 ? $profileId : '' => $data];
        }
        if ($profileId > 0) {
            try {
                return [$profileId => $this->mapper->toForm($this->profiles->getById($profileId))];
            } catch (NoSuchEntityException $exception) {
                return [];
            }
        }

        return ['' => $this->mapper->toForm($this->profileFactory->create())];
    }

    /**
     * Read-only form for roles that may view but not edit profiles.
     *
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        $meta = parent::getMeta();
        // An empty safety limit means the one in the configuration: say which.
        $meta['rules']['children']['safety_threshold']['arguments']['data']['config']['placeholder'] =
            (string) __('Default: %1%', $this->config->getSafetyThresholdPercent());
        if (!$this->authorization->isAllowed('Iranimij_OpenImport::profiles_edit')) {
            foreach (['what', 'where', 'file', 'map', 'rules', 'when'] as $fieldset) {
                $meta[$fieldset]['arguments']['data']['config']['disabled'] = true;
            }
        }

        return $meta;
    }
}
