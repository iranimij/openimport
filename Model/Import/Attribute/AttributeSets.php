<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Attribute;

use Magento\Catalog\Api\AttributeSetRepositoryInterface;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Api\ProductAttributeGroupRepositoryInterface;
use Magento\Catalog\Api\ProductAttributeManagementInterface;
use Magento\Eav\Api\Data\AttributeGroupInterfaceFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Api\SearchCriteriaBuilder;

/**
 * Product attribute sets and groups by name, cached per run.
 */
class AttributeSets
{
    /**
     * @var array<string, int>|null lower-cased set name => id
     */
    private ?array $sets = null;

    /**
     * @var array<int, array<string, true>> set id => attribute code => true
     */
    private array $members = [];

    /**
     * @param AttributeSetRepositoryInterface $setRepository
     * @param ProductAttributeGroupRepositoryInterface $groupRepository
     * @param AttributeGroupInterfaceFactory $groupFactory
     * @param ProductAttributeManagementInterface $management
     * @param SearchCriteriaBuilder $criteria
     * @param EavConfig $eavConfig
     */
    public function __construct(
        private readonly AttributeSetRepositoryInterface $setRepository,
        private readonly ProductAttributeGroupRepositoryInterface $groupRepository,
        private readonly AttributeGroupInterfaceFactory $groupFactory,
        private readonly ProductAttributeManagementInterface $management,
        private readonly SearchCriteriaBuilder $criteria,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * "Default, Bags" => ["Default", "Bags"]
     *
     * @param string $list
     * @return string[]
     */
    public function names(string $list): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $list)), static fn (string $n): bool => $n !== ''));
    }

    /**
     * @param string $name
     * @return int|null
     */
    public function id(string $name): ?int
    {
        return $this->all()[mb_strtolower(trim($name))] ?? null;
    }

    /**
     * @return array<string, int> lower-cased set name => id
     */
    public function all(): array
    {
        if ($this->sets === null) {
            $this->sets = [];
            $typeId = (int) $this->eavConfig->getEntityType(ProductAttributeInterface::ENTITY_TYPE_CODE)->getId();
            $list = $this->setRepository->getList($this->criteria->addFilter('entity_type_id', $typeId)->create());
            foreach ($list->getItems() as $set) {
                $this->sets[mb_strtolower((string) $set->getAttributeSetName())] = (int) $set->getAttributeSetId();
            }
        }

        return $this->sets;
    }

    /**
     * @param int $setId
     * @return string the set's name
     */
    public function name(int $setId): string
    {
        foreach ($this->all() as $id) {
            if ($id === $setId) {
                return (string) $this->setRepository->get($setId)->getAttributeSetName();
            }
        }

        return (string) $setId;
    }

    /**
     * @param int $setId
     * @param string $code
     * @return bool whether the attribute is in the set
     */
    public function has(int $setId, string $code): bool
    {
        if (!isset($this->members[$setId])) {
            $this->members[$setId] = [];
            foreach ($this->management->getAttributes((string) $setId) as $attribute) {
                $this->members[$setId][(string) $attribute->getAttributeCode()] = true;
            }
        }

        return isset($this->members[$setId][$code]);
    }

    /**
     * @param int $setId
     * @return void
     */
    public function forget(int $setId): void
    {
        unset($this->members[$setId]);
    }

    /**
     * Group id by name in a set; a missing group is created.
     *
     * @param int $setId
     * @param string $name
     * @return int
     */
    public function groupId(int $setId, string $name): int
    {
        $criteria = $this->criteria->addFilter('attribute_set_id', $setId)->create();
        foreach ($this->groupRepository->getList($criteria)->getItems() as $group) {
            if (mb_strtolower((string) $group->getAttributeGroupName()) === mb_strtolower($name)) {
                return (int) $group->getAttributeGroupId();
            }
        }
        $group = $this->groupFactory->create();
        $group->setAttributeSetId($setId)->setAttributeGroupName($name);

        return (int) $this->groupRepository->save($group)->getAttributeGroupId();
    }

    /**
     * Forgets everything (tests, long processes).
     *
     * @return void
     */
    public function reset(): void
    {
        $this->sets = null;
        $this->members = [];
    }
}
