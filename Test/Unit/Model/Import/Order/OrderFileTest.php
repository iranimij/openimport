<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Import\Order;

use Iranimij\OpenImport\Model\Bridge\RowError;
use Iranimij\OpenImport\Model\Import\Order\OrderFile;
use PHPUnit\Framework\TestCase;

class OrderFileTest extends TestCase
{
    private const STATUSES = ['pending' => ['state' => 'new', 'label' => 'Pending'],
        'complete' => ['state' => 'complete', 'label' => 'Complete']];

    private const GROUPS = ['general' => 1, 'not logged in' => 0];

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function row(string $sku, string $qty, string $price, array $extra = []): array
    {
        return array_merge([
            'increment_id' => 'ORD-1',
            'status' => 'complete',
            'customer_email' => 'jane@example.com',
            'billing_firstname' => 'Jane',
            'billing_lastname' => 'Doe',
            'billing_street' => 'Main 1',
            'billing_city' => 'Town',
            'billing_country_id' => 'US',
            'item_sku' => $sku,
            'item_qty_ordered' => $qty,
            'item_price' => $price,
        ], $extra);
    }

    public function testGroupsRowsByOrderNumberAndKeepsTheFirstRowsOrderColumns(): void
    {
        $errors = [];
        $orders = (new OrderFile())->group([
            $this->row('a', '1', '10', ['subtotal' => '30']),
            $this->row('b', '2', '10', ['subtotal' => 'ignored on later rows']),
            $this->row('c', '1', '5', ['increment_id' => 'ORD-2']),
            ['increment_id' => '', 'item_sku' => 'x'],
        ], $errors);

        self::assertSame(['ORD-1', 'ORD-2'], array_keys($orders));
        self::assertSame([0, 1], $orders['ORD-1']['rows']);
        self::assertSame('30', $orders['ORD-1']['order']['subtotal']);
        self::assertSame(['a', 'b'], array_column($orders['ORD-1']['items'], 'item_sku'));
        self::assertSame(2, $orders['ORD-2']['row']);
        self::assertCount(1, $errors);
        self::assertSame(3, $errors[0]->row);
        self::assertSame('The order number is empty.', $errors[0]->message);
    }

    public function testCheckFillsTheTotalsFromTheItems(): void
    {
        $file = new OrderFile();
        $errors = [];
        $orders = $file->group([
            $this->row('a', '2', '10', ['shipping_amount' => '5', 'discount_amount' => '-3', 'total_paid' => '20']),
            $this->row('b', '1', '7.5', ['item_row_total' => '7.5']),
            $this->row('child', '1', '0', ['item_parent_sku' => 'A', 'item_row_total' => '99']),
        ], $errors);
        $order = $orders['ORD-1'];

        self::assertTrue($file->check($order, self::STATUSES, self::GROUPS, $errors), $this->messages($errors));
        self::assertSame('27.5', $order['order']['subtotal']);
        self::assertSame('3', $order['order']['total_qty_ordered']);
        self::assertSame('29.5', $order['order']['grand_total']);
        self::assertSame('9.5', $order['order']['total_due']);
    }

    public function testCheckReportsWrongTotalsAndBadValues(): void
    {
        $file = new OrderFile();
        $errors = [];
        $orders = $file->group([
            $this->row('a', '2', '10', ['subtotal' => '20', 'grand_total' => '99', 'shipping_amount' => '5']),
            $this->row('b', '0', '', ['item_parent_sku' => 'nope', 'item_product_options' => '{bad']),
        ], $errors);
        $order = $orders['ORD-1'];

        self::assertFalse($file->check($order, self::STATUSES, self::GROUPS, $errors));
        $messages = $this->messages($errors);
        self::assertStringContainsString('Item b needs a quantity above 0.', $messages);
        self::assertStringContainsString('Item b needs a price.', $messages);
        self::assertStringContainsString('Item b: product options are not JSON.', $messages);
        self::assertStringContainsString('Item b names parent nope, which is not in the order.', $messages);
        // Totals are only checked once the rows are sound.
        self::assertStringNotContainsString('grand total', $messages);
        self::assertSame(1, $errors[0]->row, 'errors of an item sit on its row');

        $errors = [];
        $orders = $file->group([
            $this->row('a', '2', '10', ['subtotal' => '20', 'grand_total' => '99', 'shipping_amount' => '5']),
        ], $errors);
        $order = $orders['ORD-1'];
        self::assertFalse($file->check($order, self::STATUSES, self::GROUPS, $errors));
        self::assertSame(
            ['The grand total 99 is not subtotal + shipping + tax - discount (25).'],
            array_map(static fn (RowError $error): string => $error->message, $errors)
        );
    }

    public function testCheckReportsTheOrderColumns(): void
    {
        $file = new OrderFile();
        $errors = [];
        $orders = $file->group([
            $this->row('a', '1', '1', ['customer_email' => 'nobody', 'status' => 'shipped', 'customer_group' => 'VIP',
                'created_at' => 'yesterday-ish', 'order_currency_code' => 'dollars', 'subtotal' => 'one',
                'billing_city' => '']),
            ['increment_id' => 'ORD-2', 'customer_email' => 'x@example.com', 'status' => 'complete',
                'billing_firstname' => 'A', 'billing_lastname' => 'B', 'billing_street' => 'S', 'billing_city' => 'C',
                'billing_country_id' => 'US'],
        ], $errors);

        $first = $orders['ORD-1'];
        self::assertFalse($file->check($first, self::STATUSES, self::GROUPS, $errors));
        $messages = $this->messages($errors);
        foreach ([
            '"nobody" is not an e-mail address.',
            'Unknown order status "shipped". Statuses: pending, complete.',
            'Unknown customer group "VIP".',
            '"yesterday-ish" is not a date.',
            '"dollars" is not a currency code.',
            '"one" is not a number.',
            'The billing address has no city.',
        ] as $expected) {
            self::assertStringContainsString($expected, $messages);
        }
        $second = $orders['ORD-2'];
        $errors = [];
        self::assertFalse($file->check($second, self::STATUSES, self::GROUPS, $errors));
        self::assertSame('The order has no items (no row with an item SKU).', $errors[0]->message);
        self::assertSame('item_sku', $errors[0]->column);
    }

    public function testColumnsAndMoney(): void
    {
        $columns = OrderFile::COLUMNS;
        self::assertSame('increment_id', $columns[0]);
        self::assertContains('billing_street', $columns);
        self::assertContains('shipping_vat_id', $columns);
        self::assertSame('item_product_options', end($columns));
        self::assertSame(count(OrderFile::ORDER_COLUMNS) + 2 * count(OrderFile::ADDRESS_COLUMNS)
            + count(OrderFile::ITEM_COLUMNS), count($columns));

        $file = new OrderFile();
        self::assertSame('0', $file->money(0.0));
        self::assertSame('0', $file->money(-0.00001));
        self::assertSame('12.5', $file->money(12.5));
        self::assertSame('-3', $file->money(-3.0));
        self::assertSame('1.2346', $file->money(1.23456));
    }

    /**
     * @param RowError[] $errors
     */
    private function messages(array $errors): string
    {
        return implode("\n", array_map(static fn (RowError $error): string => $error->message, $errors));
    }
}
