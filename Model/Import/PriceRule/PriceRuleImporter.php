<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\PriceRule;

use Iranimij\OpenImport\Model\Bridge\ImporterInterface;
use Iranimij\OpenImport\Model\Bridge\ImportRequest;
use Iranimij\OpenImport\Model\Bridge\ImportResult;
use Iranimij\OpenImport\Model\Bridge\RowError;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\AbstractSource;
use Magento\Rule\Model\AbstractModel as Rule;
use Magento\SalesRule\Model\Rule as CartRule;
use Psr\Log\LoggerInterface;

/**
 * Cart price rules (with coupons) and catalog price rules by name; one class, two kinds (di.xml). A rule is matched
 * by `rule_id` when the file has one, else by name. Websites and customer groups by code, conditions as Magento's
 * condition arrays in JSON or from the simple columns (SKUs, categories, a subtotal). Add/Update creates or
 * updates, Delete removes, Replace is refused. Writes go through the rule models and resources.
 */
class PriceRuleImporter implements ImporterInterface
{
    public const CART_COLUMNS = ['rule_id', 'name', 'description', 'is_active', 'websites', 'customer_groups',
        'from_date', 'to_date', 'priority', 'stop_rules_processing', 'coupon_type', 'coupon_code', 'coupon_codes',
        'uses_per_coupon', 'uses_per_customer', 'action', 'discount_amount', 'discount_qty', 'discount_step',
        'apply_to_shipping', 'free_shipping', 'labels', 'condition_subtotal', 'condition_skus',
        'condition_categories', 'action_skus', 'action_categories', 'conditions', 'actions'];

    public const CATALOG_COLUMNS = ['rule_id', 'name', 'description', 'is_active', 'websites', 'customer_groups',
        'from_date', 'to_date', 'priority', 'stop_rules_processing', 'action', 'discount_amount', 'condition_skus',
        'condition_categories', 'conditions'];

    public const CART_ACTIONS = ['by_percent', 'by_fixed', 'cart_fixed', 'buy_x_get_y'];
    public const CATALOG_ACTIONS = ['by_percent', 'by_fixed', 'to_percent', 'to_fixed'];

    private const COUPON_TYPES = ['none' => CartRule::COUPON_TYPE_NO_COUPON, 'no' => CartRule::COUPON_TYPE_NO_COUPON,
        'no_coupon' => CartRule::COUPON_TYPE_NO_COUPON, '1' => CartRule::COUPON_TYPE_NO_COUPON,
        'specific' => CartRule::COUPON_TYPE_SPECIFIC, 'code' => CartRule::COUPON_TYPE_SPECIFIC,
        '2' => CartRule::COUPON_TYPE_SPECIFIC, 'auto' => CartRule::COUPON_TYPE_AUTO, '3' => CartRule::COUPON_TYPE_AUTO];

    private const FREE_SHIPPING = ['' => 0, '0' => 0, 'no' => 0, 'none' => 0, '1' => 1, 'matching' => 1,
        'matching items' => 1, 'items' => 1, '2' => 2, 'cart' => 2, 'whole cart' => 2, 'order' => 2];

    /**
     * @param PriceRuleRecords $records
     * @param ConditionTree $trees
     * @param LoggerInterface $logger
     * @param string $kind cart or catalog
     */
    public function __construct(
        private readonly PriceRuleRecords $records,
        private readonly ConditionTree $trees,
        private readonly LoggerInterface $logger,
        private readonly string $kind = ConditionTree::CART
    ) {
    }

    /**
     * @inheritDoc
     */
    public function run(ImportRequest $request, AbstractSource $source): ImportResult
    {
        $started = microtime(true);
        $errors = [];
        $ready = [];
        $position = 0;
        $what = $this->kind === ConditionTree::CART ? 'Cart price rules' : 'Catalog price rules';
        if ($request->behavior === Import::BEHAVIOR_REPLACE) {
            $errors[] = new RowError(
                null,
                'behavior',
                $what . ' cannot be imported with "Replace" (it would change their ids). Use Add/Update or Delete.',
                null,
                true
            );
        }
        $seen = [];
        $created = $updated = $deleted = 0;
        foreach ($source as $row) {
            $prepared = $this->prepare($row, $request->behavior, $seen);
            if (isset($prepared['error'])) {
                [$column, $message] = $prepared['error'];
                $errors[] = new RowError($position, $column, $message, $column, true);
            } else {
                $ready[$position] = $prepared;
                $seen[mb_strtolower((string) $prepared['name'])] = true;
                if ($request->behavior === Import::BEHAVIOR_DELETE) {
                    $deleted++;
                } elseif ($prepared['id'] === null) {
                    $created++;
                } else {
                    $updated++;
                }
            }
            $position++;
        }
        $invalid = count(array_filter($errors, static fn (RowError $error): bool => $error->row !== null));
        $valid = $errors === [] || ($request->skipErrors && $invalid <= $request->allowedErrors
            && !array_filter($errors, static fn (RowError $error): bool => $error->row === null));
        if ($request->dryRun || !$valid) {
            return $this->result($valid, false, $position, $invalid, $created, $updated, $deleted, $errors, $started);
        }
        $created = $updated = $deleted = 0;
        foreach ($ready as $index => $prepared) {
            try {
                if ($request->behavior === Import::BEHAVIOR_DELETE) {
                    $rule = $this->records->load($this->kind, (int) $prepared['id']);
                    if ($rule !== null) {
                        $this->records->delete($this->kind, $rule);
                    }
                    $deleted++;
                } else {
                    $this->write($prepared);
                    $prepared['id'] === null ? $created++ : $updated++;
                }
            } catch (\Throwable $exception) {
                $this->logger->error(
                    'OpenImport price rule import: ' . $exception->getMessage(),
                    ['exception' => $exception]
                );
                $errors[] = new RowError($index, 'save', $exception->getMessage(), null, !$request->skipErrors);
                $invalid++;
                if (!$request->skipErrors) {
                    return $this->result(
                        false,
                        $created + $updated + $deleted > 0,
                        $position,
                        $invalid,
                        $created,
                        $updated,
                        $deleted,
                        $errors,
                        $started
                    );
                }
            }
        }

        return $this->result(true, true, $position, $invalid, $created, $updated, $deleted, $errors, $started);
    }

    /**
     * Checks a row and turns it into what the write needs.
     *
     * @param array<string, mixed> $row
     * @param string $behavior
     * @param array<string, true> $seen lower-cased names of the rows before
     * @return array<string, mixed> with 'error' => [column, message] on a problem
     */
    private function prepare(array $row, string $behavior, array $seen): array
    {
        $get = static fn (string $column): string => trim((string) ($row[$column] ?? ''));
        $name = $get('name');
        $idText = $get('rule_id');
        if ($idText !== '' && !ctype_digit($idText)) {
            return ['error' => ['rule_id', sprintf('"%s" is not a rule id.', $idText)]];
        }
        $id = null;
        if ($idText !== '') {
            $id = (int) $idText;
            if ($this->records->load($this->kind, $id) === null) {
                return ['error' => ['rule_id', sprintf('There is no rule with id %d.', $id)]];
            }
        } elseif ($name !== '') {
            $ids = $this->records->idsByName($this->kind, $name);
            if (count($ids) > 1) {
                return ['error' => ['name', sprintf(
                    'There are %d rules named "%s" (ids %s); add a rule_id column to say which one.',
                    count($ids),
                    $name,
                    implode(', ', $ids)
                )]];
            }
            $id = $ids[0] ?? null;
        }
        if ($name === '' && $id === null) {
            return ['error' => ['name', 'The rule has no name.']];
        }
        if (isset($seen[mb_strtolower($name)])) {
            return ['error' => ['name', sprintf('"%s" appears twice in the file.', $name)]];
        }
        if ($behavior === Import::BEHAVIOR_DELETE) {
            if ($id === null) {
                return ['error' => ['name', sprintf('There is no rule named "%s".', $name)]];
            }

            return ['id' => $id, 'name' => $name];
        }
        $data = ['name' => $name];
        $problem = $this->values($get, $id, $data);
        if ($problem !== null) {
            return ['error' => $problem];
        }

        return ['id' => $id, 'name' => $name] + $data;
    }

    /**
     * The rule's values from the columns (empty columns of an existing rule keep their values).
     *
     * @param callable(string): string $get
     * @param int|null $id
     * @param array<string, mixed> $data
     * @return array{0: string, 1: string}|null the problem
     */
    private function values(callable $get, ?int $id, array &$data): ?array
    {
        $new = $id === null;
        if ($get('description') !== '' || $new) {
            $data['description'] = $get('description');
        }
        foreach (['is_active' => 1, 'stop_rules_processing' => 0, 'apply_to_shipping' => 0] as $column => $default) {
            if ($this->kind === ConditionTree::CATALOG && $column === 'apply_to_shipping') {
                continue;
            }
            $flag = $this->flag($get($column));
            if ($get($column) !== '' && $flag === null) {
                return [$column, 'use 1 or 0 (also yes/no, true/false)'];
            }
            if ($flag !== null) {
                $data[$column] = $flag ? 1 : 0;
            } elseif ($new) {
                $data[$column] = $default;
            }
        }
        $websites = $this->codes($get('websites'), $this->records->websites(), 'website');
        if (is_string($websites)) {
            return ['websites', $websites];
        }
        if ($websites !== null) {
            $data['website_ids'] = $websites;
        } elseif ($new) {
            $data['website_ids'] = array_values($this->records->websites());
        }
        $groups = $this->codes($get('customer_groups'), $this->records->groups(), 'customer group');
        if (is_string($groups)) {
            return ['customer_groups', $groups];
        }
        if ($groups !== null) {
            $data['customer_group_ids'] = $groups;
        } elseif ($new) {
            $data['customer_group_ids'] = array_values($this->records->groups());
        }
        foreach (['from_date', 'to_date'] as $column) {
            if ($get($column) === '') {
                if ($new) {
                    $data[$column] = null;
                }
                continue;
            }
            $time = strtotime($get($column));
            if ($time === false) {
                return [$column, sprintf('"%s" is not a date.', $get($column))];
            }
            $data[$column] = date('Y-m-d', $time);
        }
        if ($get('priority') !== '' && !ctype_digit($get('priority'))) {
            return ['priority', sprintf('"%s" is not a whole number.', $get('priority'))];
        }
        if ($get('priority') !== '' || $new) {
            $data['sort_order'] = (int) $get('priority');
        }
        $actions = $this->kind === ConditionTree::CART ? self::CART_ACTIONS : self::CATALOG_ACTIONS;
        $action = strtolower(str_replace(['-', ' '], '_', $get('action')));
        if ($action !== '' && !in_array($action, $actions, true)) {
            return ['action', sprintf('"%s" is not an action: use %s.', $get('action'), implode(', ', $actions))];
        }
        if ($action !== '') {
            $data['simple_action'] = $action;
        } elseif ($new) {
            return ['action', sprintf('A new rule needs an action: %s.', implode(', ', $actions))];
        }
        foreach (['discount_amount', 'discount_qty', 'discount_step', 'uses_per_coupon', 'uses_per_customer',
            'condition_subtotal'] as $column) {
            if ($get($column) !== '' && !is_numeric($get($column))) {
                return [$column, sprintf('"%s" is not a number.', $get($column))];
            }
        }
        if ($get('discount_amount') !== '') {
            $data['discount_amount'] = (float) $get('discount_amount');
        } elseif ($new) {
            return ['discount_amount', 'A new rule needs a discount amount.'];
        }
        if ($this->kind === ConditionTree::CART) {
            $problem = $this->cartValues($get, $new, $data);
            if ($problem !== null) {
                return $problem;
            }
        }

        return $this->conditions($get, $new, $data);
    }

    /**
     * @param callable(string): string $get
     * @param bool $new
     * @param array<string, mixed> $data
     * @return array{0: string, 1: string}|null
     */
    private function cartValues(callable $get, bool $new, array &$data): ?array
    {
        foreach (['discount_qty', 'discount_step'] as $column) {
            if ($get($column) !== '') {
                $data[$column] = (float) $get($column);
            } elseif ($new) {
                $data[$column] = 0;
            }
        }
        foreach (['uses_per_coupon', 'uses_per_customer'] as $column) {
            if ($get($column) !== '') {
                $data[$column] = (int) $get($column);
            } elseif ($new) {
                $data[$column] = 0;
            }
        }
        $shipping = strtolower($get('free_shipping'));
        if (!isset(self::FREE_SHIPPING[$shipping])) {
            return ['free_shipping', sprintf(
                '"%s" is not a free shipping option: use no, matching (the matching items) or cart (the whole order).',
                $get('free_shipping')
            )];
        }
        if ($shipping !== '' || $new) {
            $data['simple_free_shipping'] = self::FREE_SHIPPING[$shipping];
        }
        $type = strtolower(str_replace([' ', '-'], '_', $get('coupon_type')));
        if ($type !== '' && !isset(self::COUPON_TYPES[$type])) {
            return ['coupon_type', sprintf(
                '"%s" is not a coupon type: use none, specific or auto.',
                $get('coupon_type')
            )];
        }
        $codes = array_values(array_unique(array_filter(array_map(
            'trim',
            preg_split('/[;,]/', $get('coupon_codes')) ?: []
        ))));
        if ($type === '' && ($get('coupon_code') !== '' || $codes !== [])) {
            $type = 'specific';
        }
        if ($type !== '') {
            $data['coupon_type'] = self::COUPON_TYPES[$type];
        } elseif ($new) {
            $data['coupon_type'] = CartRule::COUPON_TYPE_NO_COUPON;
        }
        if (($data['coupon_type'] ?? null) === CartRule::COUPON_TYPE_SPECIFIC) {
            // Magento: a rule has either one code (its primary coupon) or a list of generated codes, not both.
            if ($codes !== []) {
                if ($get('coupon_code') !== '' && !in_array($get('coupon_code'), $codes, true)) {
                    array_unshift($codes, $get('coupon_code'));
                }
                $data['coupon_code'] = '';
                $data['use_auto_generation'] = 1;
            } elseif ($get('coupon_code') !== '') {
                $data['coupon_code'] = $get('coupon_code');
                $data['use_auto_generation'] = 0;
            } elseif ($new) {
                return ['coupon_code', 'A rule with a specific coupon needs a coupon_code (or coupon_codes).'];
            }
        } elseif ($type !== '') {
            $data['coupon_code'] = '';
            $data['use_auto_generation'] = 0;
        }
        $data['coupon_codes'] = $codes;
        $labels = [];
        if ($get('labels') !== '') {
            $stores = $this->records->storeCodes();
            foreach (array_filter(array_map('trim', explode('|', $get('labels')))) as $entry) {
                if (preg_match('/^([a-z0-9_]+):(.*)$/i', $entry, $match) === 1
                    && isset($stores[strtolower($match[1])])) {
                    $labels[$stores[strtolower($match[1])]] = trim($match[2]);
                } else {
                    $labels[0] = $entry;
                }
            }
            $data['store_labels'] = $labels;
        }

        return null;
    }

    /**
     * The condition (and action) trees: the JSON columns, else the simple columns.
     *
     * @param callable(string): string $get
     * @param bool $new
     * @param array<string, mixed> $data
     * @return array{0: string, 1: string}|null
     */
    private function conditions(callable $get, bool $new, array &$data): ?array
    {
        $cart = $this->kind === ConditionTree::CART;
        if ($get('conditions') !== '') {
            $tree = $this->trees->parse($get('conditions'), $this->kind, 'Combine');
            if (is_string($tree)) {
                return ['conditions', 'The conditions are not usable: ' . $tree . '.'];
            }
            $data['conditions_tree'] = $tree;
        } else {
            $children = [];
            if ($cart && $get('condition_subtotal') !== '') {
                $children[] = $this->trees->subtotal('>=', $get('condition_subtotal'));
            }
            if ($get('condition_skus') !== '') {
                $children[] = $this->trees->productIn($this->kind, 'sku', $this->list($get('condition_skus')), $cart);
            }
            if ($get('condition_categories') !== '') {
                $ids = $this->categoryIds($get('condition_categories'));
                if (is_string($ids)) {
                    return ['condition_categories', $ids];
                }
                $children[] = $this->trees->productIn($this->kind, 'category_ids', implode(', ', $ids), $cart);
            }
            if ($children !== [] || $new) {
                $data['conditions_tree'] = $this->trees->build($this->kind, 'Combine', $children);
            }
        }
        if (!$cart) {
            return null;
        }
        if ($get('actions') !== '') {
            $tree = $this->trees->parse($get('actions'), $this->kind, 'Product\\Combine');
            if (is_string($tree)) {
                return ['actions', 'The actions are not usable: ' . $tree . '.'];
            }
            $data['actions_tree'] = $tree;
        } else {
            $children = [];
            if ($get('action_skus') !== '') {
                $children[] = $this->trees->productIn($this->kind, 'sku', $this->list($get('action_skus')), false);
            }
            if ($get('action_categories') !== '') {
                $ids = $this->categoryIds($get('action_categories'));
                if (is_string($ids)) {
                    return ['action_categories', $ids];
                }
                $children[] = $this->trees->productIn($this->kind, 'category_ids', implode(', ', $ids), false);
            }
            if ($children !== [] || $new) {
                $data['actions_tree'] = $this->trees->build($this->kind, 'Product\\Combine', $children);
            }
        }

        return null;
    }

    /**
     * Writes a prepared rule.
     *
     * @param array<string, mixed> $prepared
     * @return void
     */
    private function write(array $prepared): void
    {
        $rule = $prepared['id'] === null
            ? $this->records->create($this->kind)
            : $this->records->load($this->kind, (int) $prepared['id']);
        if ($rule === null) {
            throw new \RuntimeException(sprintf('Rule %d disappeared during the import.', $prepared['id']));
        }
        $codes = $prepared['coupon_codes'] ?? [];
        $conditions = $prepared['conditions_tree'] ?? null;
        $actions = $prepared['actions_tree'] ?? null;
        unset($prepared['id'], $prepared['coupon_codes'], $prepared['conditions_tree'], $prepared['actions_tree']);
        $rule->addData($prepared);
        if ($conditions !== null) {
            // A fresh tree: the saved one would otherwise be loaded first and the file's added to it.
            $rule->setConditions($rule->getConditionsInstance()->setData([
                'rule' => $rule, 'id' => '1', 'prefix' => 'conditions', 'conditions' => [],
            ]));
            $rule->unsetData('conditions_serialized');
            $rule->getConditions()->loadArray($conditions);
        }
        if ($actions !== null) {
            $rule->setActions($rule->getActionsInstance()->setData([
                'rule' => $rule, 'id' => '1', 'prefix' => 'actions', 'actions' => [],
            ]));
            $rule->unsetData('actions_serialized');
            $rule->getActions()->loadArray($actions);
        }
        $this->records->save($this->kind, $rule);
        if ($rule instanceof CartRule && $codes !== []) {
            $owners = $this->records->couponOwners($codes);
            foreach ($codes as $code) {
                $owner = $owners[$code] ?? null;
                if ($owner === (int) $rule->getId()) {
                    continue;
                }
                if ($owner !== null) {
                    throw new \RuntimeException(sprintf('Coupon code "%s" belongs to rule %d already.', $code, $owner));
                }
                $this->records->addCoupon($rule, $code);
            }
        }
    }

    /**
     * @param string $text comma list of codes, "all" or ""
     * @param array<string, int> $known lower-cased code => id
     * @param string $what
     * @return int[]|string|null ids; null when the column is empty; the problem as text
     */
    private function codes(string $text, array $known, string $what): array|string|null
    {
        if ($text === '') {
            return null;
        }
        if (strtolower($text) === 'all') {
            return array_values($known);
        }
        $ids = [];
        foreach (array_filter(array_map('trim', explode(',', $text))) as $code) {
            if (ctype_digit($code) && in_array((int) $code, $known, true)) {
                $ids[] = (int) $code;
                continue;
            }
            if (!isset($known[strtolower($code)])) {
                return sprintf('there is no %s "%s" (known: %s)', $what, $code, implode(', ', array_keys($known)));
            }
            $ids[] = $known[strtolower($code)];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param string $text comma or semicolon list of category paths
     * @return string[]|string the ids, or the problem
     */
    private function categoryIds(string $text): array|string
    {
        $ids = [];
        foreach (array_filter(array_map('trim', preg_split('/[;,]/', $text) ?: [])) as $path) {
            $id = ctype_digit($path) ? (int) $path : $this->records->categoryId($path);
            if ($id === null) {
                return sprintf('there is no category "%s"', $path);
            }
            $ids[] = (string) $id;
        }

        return $ids;
    }

    /**
     * @param string $text
     * @return string a comma list as Magento's "is one of" expects it
     */
    private function list(string $text): string
    {
        return implode(', ', array_filter(array_map('trim', preg_split('/[;,]/', $text) ?: [])));
    }

    /**
     * @param string $value
     * @return bool|null
     */
    private function flag(string $value): ?bool
    {
        return match (strtolower($value)) {
            '1', 'yes', 'true', 'ja', 'y', 'on' => true,
            '0', 'no', 'false', 'nein', 'n', 'off' => false,
            default => null,
        };
    }

    /**
     * @param bool $valid
     * @param bool $imported
     * @param int $rows
     * @param int $invalid
     * @param int $created
     * @param int $updated
     * @param int $deleted
     * @param RowError[] $errors
     * @param float $started
     * @return ImportResult
     */
    private function result(
        bool $valid,
        bool $imported,
        int $rows,
        int $invalid,
        int $created,
        int $updated,
        int $deleted,
        array $errors,
        float $started
    ): ImportResult {
        return new ImportResult(
            $valid,
            $imported,
            $rows,
            $rows,
            $invalid,
            $created,
            $updated,
            $deleted,
            $errors,
            null,
            microtime(true) - $started
        );
    }
}
