<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Order;

use Iranimij\OpenImport\Model\Bridge\RowError;

/**
 * Turns the rows of an order file (one row per item, the layout of OpenImport's own order export) into orders
 * and checks them: the first row of an order carries the order, every row one item; children of configurable
 * and bundle items name their parent's SKU. Totals are taken from the file and checked against the items.
 */
class OrderFile
{
    public const ORDER_COLUMNS = [
        'increment_id', 'state', 'status', 'created_at', 'store_code', 'customer_email', 'customer_firstname',
        'customer_lastname', 'customer_group', 'customer_is_guest', 'order_currency_code', 'subtotal',
        'subtotal_incl_tax', 'discount_amount', 'discount_description', 'coupon_code', 'shipping_amount',
        'shipping_incl_tax', 'tax_amount', 'grand_total', 'total_paid', 'total_refunded', 'total_due',
        'total_qty_ordered', 'weight', 'shipping_method', 'shipping_description', 'payment_method',
        'payment_last_trans_id', 'payment_po_number', 'customer_note', 'tracking_numbers', 'tracking_carriers',
        'remote_ip',
    ];

    public const ADDRESS_COLUMNS = ['firstname', 'lastname', 'company', 'street', 'city', 'postcode', 'region',
        'country_id', 'telephone', 'email', 'vat_id'];

    public const ITEM_COLUMNS = [
        'item_sku', 'item_name', 'item_product_type', 'item_parent_sku', 'item_qty_ordered', 'item_qty_invoiced',
        'item_qty_shipped', 'item_qty_refunded', 'item_qty_canceled', 'item_price', 'item_price_incl_tax',
        'item_row_total', 'item_row_total_incl_tax', 'item_discount_amount', 'item_tax_amount', 'item_tax_percent',
        'item_weight', 'item_product_options',
    ];

    public const AMOUNTS = ['subtotal', 'subtotal_incl_tax', 'discount_amount', 'shipping_amount',
        'shipping_incl_tax', 'tax_amount', 'grand_total', 'total_paid', 'total_refunded', 'total_due', 'weight'];

    public const ITEM_AMOUNTS = ['item_qty_ordered', 'item_qty_invoiced', 'item_qty_shipped', 'item_qty_refunded',
        'item_qty_canceled', 'item_price', 'item_price_incl_tax', 'item_row_total', 'item_row_total_incl_tax',
        'item_discount_amount', 'item_tax_amount', 'item_tax_percent', 'item_weight'];

    private const TOLERANCE = 0.011;

    /**
     * Every column of the file: the order columns, the billing and shipping address columns, the item columns.
     */
    public const COLUMNS = [
        ...self::ORDER_COLUMNS,
        'billing_firstname', 'billing_lastname', 'billing_company', 'billing_street', 'billing_city',
        'billing_postcode', 'billing_region', 'billing_country_id', 'billing_telephone', 'billing_email',
        'billing_vat_id',
        'shipping_firstname', 'shipping_lastname', 'shipping_company', 'shipping_street', 'shipping_city',
        'shipping_postcode', 'shipping_region', 'shipping_country_id', 'shipping_telephone', 'shipping_email',
        'shipping_vat_id',
        ...self::ITEM_COLUMNS,
    ];

    /**
     * Groups the rows by order number. Every order: 'row' (first row position), 'rows', 'order' (the order
     * columns of the first row), 'billing', 'shipping', 'items' (each with its 'row' and item columns).
     *
     * @param iterable<int, array<string, mixed>> $rows
     * @param RowError[] $errors
     * @return array<string, array<string, mixed>>
     */
    public function group(iterable $rows, array &$errors): array
    {
        $orders = [];
        $position = 0;
        foreach ($rows as $row) {
            $key = trim((string) ($row['increment_id'] ?? ''));
            if ($key === '') {
                $errors[] = new RowError($position, 'increment_id', 'The order number is empty.', 'increment_id', true);
                $position++;
                continue;
            }
            if (!isset($orders[$key])) {
                $order = [];
                foreach (self::ORDER_COLUMNS as $column) {
                    $order[$column] = trim((string) ($row[$column] ?? ''));
                }
                $addresses = [];
                foreach (['billing', 'shipping'] as $kind) {
                    foreach (self::ADDRESS_COLUMNS as $column) {
                        $addresses[$kind][$column] = trim((string) ($row[$kind . '_' . $column] ?? ''));
                    }
                }
                $orders[$key] = ['row' => $position, 'rows' => [], 'order' => $order, 'billing' => $addresses['billing'],
                    'shipping' => $addresses['shipping'], 'items' => []];
            }
            $orders[$key]['rows'][] = $position;
            $item = ['row' => $position];
            foreach (self::ITEM_COLUMNS as $column) {
                $item[$column] = trim((string) ($row[$column] ?? ''));
            }
            if ($item['item_sku'] !== '') {
                $orders[$key]['items'][] = $item;
            }
            $position++;
        }

        return $orders;
    }

    /**
     * Checks one order; numbers are normalised in place. Problems become errors on the order's first row (or
     * the item's row).
     *
     * @param array<string, mixed> $order
     * @param array<string, array<string, string>> $statuses
     * @param array<string, int> $groups
     * @param RowError[] $errors
     * @param array<string, true> $countries upper-case country codes; empty: not checked
     * @return bool
     */
    public function check(array &$order, array $statuses, array $groups, array &$errors, array $countries = []): bool
    {
        $before = count($errors);
        $row = (int) $order['row'];
        $data = &$order['order'];
        $error = static function (string $column, string $message, ?int $at = null) use (&$errors, $row): void {
            $errors[] = new RowError($at ?? $row, $column, $message, $column, true);
        };
        if ($data['customer_email'] === '' || filter_var($data['customer_email'], FILTER_VALIDATE_EMAIL) === false) {
            $error('customer_email', sprintf('"%s" is not an e-mail address.', $data['customer_email']));
        }
        if ($data['status'] !== '' && !isset($statuses[$data['status']])) {
            $error('status', sprintf('Unknown order status "%s". Statuses: %s.', $data['status'], implode(', ', array_keys($statuses))));
        }
        if ($data['status'] === '' && $data['state'] === '') {
            $error('status', 'The order needs a status (or a state).');
        }
        if ($data['customer_group'] !== '' && !isset($groups[mb_strtolower($data['customer_group'])])
            && !ctype_digit($data['customer_group'])) {
            $error('customer_group', sprintf('Unknown customer group "%s".', $data['customer_group']));
        }
        if ($data['created_at'] !== '' && strtotime($data['created_at']) === false) {
            $error('created_at', sprintf('"%s" is not a date.', $data['created_at']));
        }
        if ($data['order_currency_code'] !== '' && preg_match('/^[A-Z]{3}$/', $data['order_currency_code']) !== 1) {
            $error('order_currency_code', sprintf('"%s" is not a currency code.', $data['order_currency_code']));
        }
        foreach (self::AMOUNTS as $column) {
            if ($data[$column] !== '' && !is_numeric($data[$column])) {
                $error($column, sprintf('"%s" is not a number.', $data[$column]));
            }
        }
        foreach (['firstname', 'lastname', 'street', 'city', 'country_id'] as $column) {
            if ($order['billing'][$column] === '') {
                $error('billing_' . $column, sprintf('The billing address has no %s.', str_replace('_id', '', $column)));
            }
        }
        foreach (['billing', 'shipping'] as $type) {
            $country = (string) ($order[$type]['country_id'] ?? '');
            if ($countries !== [] && $country !== '' && !isset($countries[strtoupper($country)])) {
                $error($type . '_country_id', sprintf(
                    '"%s" is not a country code Magento knows; use two letters such as DE, AT or US.',
                    $country
                ));
            }
        }
        if ($order['items'] === []) {
            $error('item_sku', 'The order has no items (no row with an item SKU).');
        }
        $skus = [];
        foreach ($order['items'] as $index => &$item) {
            $at = (int) $item['row'];
            foreach (self::ITEM_AMOUNTS as $column) {
                if ($item[$column] !== '' && !is_numeric($item[$column])) {
                    $error($column, sprintf('"%s" is not a number.', $item[$column]), $at);
                }
            }
            if ($item['item_qty_ordered'] === '' || (is_numeric($item['item_qty_ordered']) && (float) $item['item_qty_ordered'] <= 0)) {
                $error('item_qty_ordered', sprintf('Item %s needs a quantity above 0.', $item['item_sku']), $at);
            }
            if ($item['item_price'] === '') {
                $error('item_price', sprintf('Item %s needs a price.', $item['item_sku']), $at);
            }
            if ($item['item_product_options'] !== '' && json_decode($item['item_product_options'], true) === null) {
                $error('item_product_options', sprintf('Item %s: product options are not JSON.', $item['item_sku']), $at);
            }
            $skus[mb_strtolower($item['item_sku'])] = $index;
        }
        unset($item);
        foreach ($order['items'] as &$item) {
            if ($item['item_parent_sku'] !== '' && !isset($skus[mb_strtolower($item['item_parent_sku'])])) {
                $error(
                    'item_parent_sku',
                    sprintf('Item %s names parent %s, which is not in the order.', $item['item_sku'], $item['item_parent_sku']),
                    (int) $item['row']
                );
            }
        }
        unset($item);
        if (count($errors) === $before) {
            $this->totals($order, $error);
        }

        return count($errors) === $before;
    }

    /**
     * Totals from the file against its items (parents only; children of configurables carry no row total).
     *
     * @param array<string, mixed> $order
     * @param callable(string, string): void $error
     * @return void
     */
    private function totals(array &$order, callable $error): void
    {
        $data = &$order['order'];
        $subtotal = 0.0;
        $qty = 0.0;
        foreach ($order['items'] as $item) {
            if ($item['item_parent_sku'] !== '') {
                continue;
            }
            $rowTotal = $item['item_row_total'] !== ''
                ? (float) $item['item_row_total']
                : (float) $item['item_price'] * (float) $item['item_qty_ordered'];
            $subtotal += $rowTotal;
            $qty += (float) $item['item_qty_ordered'];
        }
        if ($data['subtotal'] === '') {
            $data['subtotal'] = $this->money($subtotal);
        } elseif (abs((float) $data['subtotal'] - $subtotal) > self::TOLERANCE) {
            $error('subtotal', sprintf(
                'The subtotal %s does not match the items (%s).',
                $data['subtotal'],
                $this->money($subtotal)
            ));
        }
        if ($data['total_qty_ordered'] === '') {
            $data['total_qty_ordered'] = $this->money($qty);
        }
        $expected = (float) $data['subtotal'] + (float) ($data['shipping_amount'] ?: 0) + (float) ($data['tax_amount'] ?: 0)
            - abs((float) ($data['discount_amount'] ?: 0));
        if ($data['grand_total'] === '') {
            $data['grand_total'] = $this->money($expected);
        } elseif (abs((float) $data['grand_total'] - $expected) > self::TOLERANCE) {
            $error('grand_total', sprintf(
                'The grand total %s is not subtotal + shipping + tax - discount (%s).',
                $data['grand_total'],
                $this->money($expected)
            ));
        }
        if ($data['total_due'] === '' && $data['total_paid'] !== '') {
            $data['total_due'] = $this->money((float) $data['grand_total'] - (float) $data['total_paid']);
        }
    }

    /**
     * @param float $value
     * @return string
     */
    public function money(float $value): string
    {
        $text = number_format(round($value, 4), 4, '.', '');

        return rtrim(rtrim($text, '0'), '.') === '' || rtrim(rtrim($text, '0'), '.') === '-0'
            ? '0'
            : rtrim(rtrim($text, '0'), '.');
    }
}
