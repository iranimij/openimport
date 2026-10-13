<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Controller\Adminhtml\Profile;

use Iranimij\OpenImport\Model\Entity\EntityRegistry;
use Iranimij\OpenImport\Model\Entity\FieldList;
use Iranimij\OpenImport\Model\Mapping\AutoMatcher;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Magento fields of an entity for the mapping editor, plus an auto-match proposal when the file's columns are sent.
 */
class Fields extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Iranimij_OpenImport::profiles';

    /**
     * @param Context $context
     * @param FieldList $fields
     * @param EntityRegistry $entities
     * @param AutoMatcher $autoMatcher
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Context $context,
        private readonly FieldList $fields,
        private readonly EntityRegistry $entities,
        private readonly AutoMatcher $autoMatcher,
        private readonly StoreManagerInterface $storeManager
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
        $entity = (string) $this->getRequest()->getParam('entity');
        $fields = $this->fields->fields($entity);
        $info = $this->entities->resolve($entity, []);
        $stores = [];
        if ($info->storeViews) {
            foreach ($this->storeManager->getStores() as $store) {
                $stores[] = ['code' => (string) $store->getCode(), 'name' => (string) $store->getName()];
            }
        }
        $columns = $this->getRequest()->getParam('columns');
        $matches = is_array($columns) && $columns !== []
            ? $this->autoMatcher->match(
                array_map('strval', $columns),
                array_column($fields, 'code'),
                $info,
                array_column($stores, 'code')
            )
            : [];

        return $json->setData(['fields' => $fields, 'stores' => $stores, 'matches' => $matches]);
    }
}
