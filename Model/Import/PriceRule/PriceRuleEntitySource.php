<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\PriceRule;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Export\EntitySourceInterface;
use Magento\Rule\Model\AbstractModel as Rule;
use Magento\SalesRule\Model\Rule as CartRule;

/**
 * Cart or catalog price rule export: one row per rule with websites and customer groups as codes, the coupon
 * codes, store labels, and the conditions as JSON. The file imports back as it is.
 */
class PriceRuleEntitySource implements EntitySourceInterface
{
    /**
     * @param PriceRuleRecords $records
     * @param ConditionTree $trees
     * @param string $kind cart or catalog
     */
    public function __construct(
        private readonly PriceRuleRecords $records,
        private readonly ConditionTree $trees,
        private readonly string $kind = ConditionTree::CART
    ) {
    }

    /**
     * @inheritDoc
     */
    public function read(ProfileInterface $profile, callable $onHeader, callable $onRow): void
    {
        $onHeader($this->columns($profile->getEntity()));
        foreach ($this->records->all($this->kind) as $rule) {
            $onRow($this->row($rule));
        }
    }

    /**
     * @inheritDoc
     */
    public function finish(ProfileInterface $profile, int $runId): void
    {
    }

    /**
     * @inheritDoc
     */
    public function columns(string $entity): array
    {
        return $this->kind === ConditionTree::CART
            ? PriceRuleImporter::CART_COLUMNS
            : PriceRuleImporter::CATALOG_COLUMNS;
    }

    /**
     * A rule as a file row.
     *
     * @param Rule $rule
     * @return array<string, string>
     */
    public function row(Rule $rule): array
    {
        $text = static fn (mixed $value): string => $value === null ? '' : (string) $value;
        $row = [
            'rule_id' => $text($rule->getId()),
            'name' => $text($rule->getData('name')),
            'description' => $text($rule->getData('description')),
            'is_active' => (string) (int) $rule->getData('is_active'),
            'websites' => implode(',', array_map(
                fn (int $id): string => $this->records->websiteCode($id),
                array_map('intval', (array) $rule->getWebsiteIds())
            )),
            'customer_groups' => implode(',', array_map(
                fn (int $id): string => $this->records->groupCode($id),
                $this->records->groupIds($rule)
            )),
            'from_date' => $this->date($rule->getData('from_date')),
            'to_date' => $this->date($rule->getData('to_date')),
            'priority' => $text($rule->getData('sort_order')),
            'stop_rules_processing' => (string) (int) $rule->getData('stop_rules_processing'),
            'action' => $text($rule->getData('simple_action')),
            'discount_amount' => $this->number($rule->getData('discount_amount')),
            'condition_skus' => '',
            'condition_categories' => '',
            'conditions' => $this->trees->toJson($rule->getConditions()->asArray()),
        ];
        if ($rule instanceof CartRule) {
            $labels = [];
            foreach ((array) $rule->getStoreLabels() as $storeId => $label) {
                $code = $this->records->storeCode((int) $storeId);
                $labels[] = $code === '' ? (string) $label : $code . ':' . $label;
            }
            $type = (int) $rule->getData('coupon_type');
            $row += [
                'coupon_type' => match ($type) {
                    CartRule::COUPON_TYPE_SPECIFIC => 'specific',
                    CartRule::COUPON_TYPE_AUTO => 'auto',
                    default => 'none',
                },
                'coupon_code' => $type === CartRule::COUPON_TYPE_SPECIFIC && !$rule->getData('use_auto_generation')
                    ? $text($rule->getPrimaryCoupon()->getCode()) : '',
                'coupon_codes' => implode(';', $this->records->couponCodes((int) $rule->getId())),
                'uses_per_coupon' => $text($rule->getData('uses_per_coupon')),
                'uses_per_customer' => $text($rule->getData('uses_per_customer')),
                'discount_qty' => $this->number($rule->getData('discount_qty')),
                'discount_step' => $text($rule->getData('discount_step')),
                'apply_to_shipping' => (string) (int) $rule->getData('apply_to_shipping'),
                'free_shipping' => match ((int) $rule->getData('simple_free_shipping')) {
                    1 => 'matching',
                    2 => 'cart',
                    default => 'no',
                },
                'labels' => implode('|', $labels),
                'condition_subtotal' => '',
                'action_skus' => '',
                'action_categories' => '',
                'actions' => $this->trees->toJson($rule->getActions()->asArray()),
            ];
        }
        $ordered = [];
        foreach ($this->columns('') as $column) {
            $ordered[$column] = $row[$column] ?? '';
        }

        return $ordered;
    }

    /**
     * @param mixed $value
     * @return string Y-m-d or ""
     */
    private function date(mixed $value): string
    {
        $text = (string) $value;

        return $text === '' ? '' : substr($text, 0, 10);
    }

    /**
     * @param mixed $value
     * @return string without trailing zeros
     */
    private function number(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $text = rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');

        return $text === '' ? '0' : $text;
    }
}
