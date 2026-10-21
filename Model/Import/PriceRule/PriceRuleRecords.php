<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\PriceRule;

use Iranimij\OpenImport\Model\Import\Category\CategoryTree;
use Magento\CatalogRule\Model\ResourceModel\Rule as CatalogRuleResource;
use Magento\CatalogRule\Model\ResourceModel\Rule\CollectionFactory as CatalogRuleCollectionFactory;
use Magento\CatalogRule\Model\Rule as CatalogRule;
use Magento\CatalogRule\Model\RuleFactory as CatalogRuleFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Rule\Model\AbstractModel as Rule;
use Magento\SalesRule\Model\Coupon;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\ResourceModel\Coupon as CouponResource;
use Magento\SalesRule\Model\ResourceModel\Rule as CartRuleResource;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory as CartRuleCollectionFactory;
use Magento\SalesRule\Model\Rule as CartRule;
use Magento\SalesRule\Model\RuleFactory as CartRuleFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The shop's cart and catalog price rules, their coupons, and what the file names them by: websites and customer
 * groups by code, categories by path. Writes go through the rule models and their resources, as the admin's rule
 * forms do.
 */
class PriceRuleRecords
{
    /**
     * @var array<string, int>|null lower-cased customer group code => id
     */
    private ?array $groups = null;

    /**
     * @param ResourceConnection $resource
     * @param StoreManagerInterface $storeManager
     * @param CategoryTree $categories
     * @param CartRuleFactory $cartRuleFactory
     * @param CartRuleResource $cartRuleResource
     * @param CartRuleCollectionFactory $cartRuleCollectionFactory
     * @param CatalogRuleFactory $catalogRuleFactory
     * @param CatalogRuleResource $catalogRuleResource
     * @param CatalogRuleCollectionFactory $catalogRuleCollectionFactory
     * @param CouponFactory $couponFactory
     * @param CouponResource $couponResource
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly CategoryTree $categories,
        private readonly CartRuleFactory $cartRuleFactory,
        private readonly CartRuleResource $cartRuleResource,
        private readonly CartRuleCollectionFactory $cartRuleCollectionFactory,
        private readonly CatalogRuleFactory $catalogRuleFactory,
        private readonly CatalogRuleResource $catalogRuleResource,
        private readonly CatalogRuleCollectionFactory $catalogRuleCollectionFactory,
        private readonly CouponFactory $couponFactory,
        private readonly CouponResource $couponResource
    ) {
    }

    /**
     * @return array<string, int> lower-cased website code => id
     */
    public function websites(): array
    {
        $websites = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $websites[strtolower((string) $website->getCode())] = (int) $website->getId();
        }

        return $websites;
    }

    /**
     * @param int $id
     * @return string
     */
    public function websiteCode(int $id): string
    {
        foreach ($this->storeManager->getWebsites() as $website) {
            if ((int) $website->getId() === $id) {
                return (string) $website->getCode();
            }
        }

        return (string) $id;
    }

    /**
     * @return array<string, int> lower-cased store view code => id
     */
    public function storeCodes(): array
    {
        $codes = [];
        foreach ($this->storeManager->getStores() as $store) {
            $codes[strtolower((string) $store->getCode())] = (int) $store->getId();
        }

        return $codes;
    }

    /**
     * @param int $id
     * @return string the store view code, "" for 0 (every store view)
     */
    public function storeCode(int $id): string
    {
        if ($id === 0) {
            return '';
        }
        foreach ($this->storeManager->getStores() as $store) {
            if ((int) $store->getId() === $id) {
                return (string) $store->getCode();
            }
        }

        return (string) $id;
    }

    /**
     * @return array<string, int> lower-cased customer group code => id
     */
    public function groups(): array
    {
        if ($this->groups === null) {
            $this->groups = [];
            $connection = $this->resource->getConnection();
            $rows = $connection->fetchPairs(
                $connection->select()
                    ->from(
                        $this->resource->getTableName('customer_group'),
                        ['customer_group_code', 'customer_group_id']
                    )
            );
            foreach ($rows as $code => $id) {
                $this->groups[mb_strtolower((string) $code)] = (int) $id;
            }
        }

        return $this->groups;
    }

    /**
     * @param int $id
     * @return string
     */
    public function groupCode(int $id): string
    {
        $code = array_search($id, $this->groups(), true);

        return $code === false ? (string) $id : $this->groupName($id);
    }

    /**
     * @param int $id
     * @return string the code as written in the shop (not lower-cased)
     */
    private function groupName(int $id): string
    {
        $connection = $this->resource->getConnection();
        $name = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('customer_group'), ['customer_group_code'])
                ->where('customer_group_id = ?', $id)
        );

        return is_string($name) ? $name : (string) $id;
    }

    /**
     * @param Rule $rule
     * @return int[] the customer groups a rule applies to
     */
    public function groupIds(Rule $rule): array
    {
        $ids = $rule instanceof CartRule || $rule instanceof CatalogRule ? (array) $rule->getCustomerGroupIds() : [];

        return array_map('intval', $ids);
    }

    /**
     * @param string $path
     * @return int|null
     */
    public function categoryId(string $path): ?int
    {
        return $this->categories->id($path);
    }

    /**
     * @param int $id
     * @return string|null
     */
    public function categoryPath(int $id): ?string
    {
        return $this->categories->path($id);
    }

    /**
     * Rules named so (names are not unique in Magento; the import reports several).
     *
     * @param string $kind cart or catalog
     * @param string $name
     * @return int[] rule ids
     */
    public function idsByName(string $kind, string $name): array
    {
        $connection = $this->resource->getConnection();
        $ids = $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName($this->table($kind)), ['rule_id'])
                ->where('name = ?', $name)
                ->order('rule_id ASC')
        );

        return array_map('intval', $ids);
    }

    /**
     * @param string $kind
     * @param int $id
     * @return Rule|null the loaded rule
     */
    public function load(string $kind, int $id): ?Rule
    {
        $rule = $this->create($kind);
        $this->resourceOf($kind)->load($rule, $id);

        return $rule->getId() ? $rule : null;
    }

    /**
     * @param string $kind
     * @return Rule a fresh rule model
     */
    public function create(string $kind): Rule
    {
        return $kind === ConditionTree::CART ? $this->cartRuleFactory->create() : $this->catalogRuleFactory->create();
    }

    /**
     * @param string $kind
     * @param Rule $rule
     * @return void
     */
    public function save(string $kind, Rule $rule): void
    {
        $this->resourceOf($kind)->save($rule);
    }

    /**
     * @param string $kind
     * @param Rule $rule
     * @return void
     */
    public function delete(string $kind, Rule $rule): void
    {
        $this->resourceOf($kind)->delete($rule);
    }

    /**
     * Every rule of the kind, by id.
     *
     * @param string $kind
     * @return \Generator<int, Rule>
     */
    public function all(string $kind): \Generator
    {
        $collection = $kind === ConditionTree::CART
            ? $this->cartRuleCollectionFactory->create()
            : $this->catalogRuleCollectionFactory->create();
        $collection->setOrder('rule_id', 'ASC');
        foreach ($collection as $rule) {
            if ($rule instanceof Rule) {
                yield $rule;
            }
        }
    }

    /**
     * @param string $kind
     * @return int
     */
    public function count(string $kind): int
    {
        $collection = $kind === ConditionTree::CART
            ? $this->cartRuleCollectionFactory->create()
            : $this->catalogRuleCollectionFactory->create();

        return $collection->getSize();
    }

    /**
     * The coupon codes of a cart rule besides its primary code.
     *
     * @param int $ruleId
     * @return string[]
     */
    public function couponCodes(int $ruleId): array
    {
        $connection = $this->resource->getConnection();
        $codes = $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('salesrule_coupon'), ['code'])
                ->where('rule_id = ?', $ruleId)
                ->where('is_primary IS NULL OR is_primary = 0')
                ->order('coupon_id ASC')
        );

        return array_map('strval', $codes);
    }

    /**
     * @param string[] $codes
     * @return array<string, int> code => the rule that owns it
     */
    public function couponOwners(array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $pairs = $connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName('salesrule_coupon'), ['code', 'rule_id'])
                ->where('code IN (?)', array_values($codes))
        );
        $owners = [];
        foreach ($pairs as $code => $ruleId) {
            $owners[(string) $code] = (int) $ruleId;
        }

        return $owners;
    }

    /**
     * Adds a generated-type coupon to a rule.
     *
     * @param CartRule $rule
     * @param string $code
     * @return void
     */
    public function addCoupon(CartRule $rule, string $code): void
    {
        /** @var Coupon $coupon */
        $coupon = $this->couponFactory->create();
        $coupon->setRuleId((int) $rule->getId())
            ->setCode($code)
            ->setUsageLimit($rule->getUsesPerCoupon() ? (int) $rule->getUsesPerCoupon() : null)
            ->setUsagePerCustomer($rule->getUsesPerCustomer() ? (int) $rule->getUsesPerCustomer() : null)
            ->setType(Coupon::TYPE_GENERATED);
        $this->couponResource->save($coupon);
    }

    /**
     * @param string $kind
     * @return string the rule table
     */
    private function table(string $kind): string
    {
        return $kind === ConditionTree::CART ? 'salesrule' : 'catalogrule';
    }

    /**
     * @param string $kind
     * @return CartRuleResource|CatalogRuleResource
     */
    private function resourceOf(string $kind): CartRuleResource|CatalogRuleResource
    {
        return $kind === ConditionTree::CART ? $this->cartRuleResource : $this->catalogRuleResource;
    }
}
