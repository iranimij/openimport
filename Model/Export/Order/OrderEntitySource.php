<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Export\Order;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Export\EntitySourceInterface;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Storage\ExportMarkStore;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;

/**
 * OpenImport's own order export (Magento has none): one row per order line (or per order), with the order's
 * totals, customer, billing and shipping address, payment, shipments and tracking numbers. Read in pages of
 * orders straight from the sales tables (read only).
 *
 * export_options.order: rows (item | order), statuses (comma-separated), from, to (dates, store time zone is not
 * applied: order created_at is UTC), store_ids (comma-separated), only (all | new | new_or_changed: against the
 * profile's export marks), items (parents | all: child items of configurable/bundle products).
 */
class OrderEntitySource implements EntitySourceInterface
{
    private const PAGE = 200;

    private const ORDER_COLUMNS = [
        'increment_id', 'state', 'status', 'created_at', 'updated_at', 'store_code', 'store_name',
        'customer_email', 'customer_firstname', 'customer_lastname', 'customer_group', 'customer_is_guest',
        'order_currency_code', 'subtotal', 'subtotal_incl_tax', 'discount_amount', 'discount_description',
        'coupon_code', 'shipping_amount', 'shipping_incl_tax', 'tax_amount', 'grand_total', 'total_paid',
        'total_refunded', 'total_due', 'total_qty_ordered', 'weight', 'shipping_method', 'shipping_description',
        'payment_method', 'payment_last_trans_id', 'payment_po_number', 'customer_note',
        'shipment_increment_ids', 'tracking_numbers', 'tracking_carriers', 'remote_ip',
    ];

    private const ADDRESS_COLUMNS = ['firstname', 'lastname', 'company', 'street', 'city', 'postcode', 'region',
        'country_id', 'telephone', 'email', 'vat_id'];

    private const ITEM_COLUMNS = [
        'item_id', 'item_sku', 'item_name', 'item_product_type', 'item_parent_sku', 'item_qty_ordered',
        'item_qty_invoiced', 'item_qty_shipped', 'item_qty_refunded', 'item_qty_canceled', 'item_price',
        'item_price_incl_tax', 'item_row_total', 'item_row_total_incl_tax', 'item_discount_amount',
        'item_tax_amount', 'item_tax_percent', 'item_weight', 'item_product_options',
    ];

    /**
     * @var int[] order ids that made it into the file of the current run
     */
    private array $exported = [];

    /**
     * @param ResourceConnection $resource
     * @param ExportMarkStore $marks
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ExportMarkStore $marks
    ) {
    }

    /**
     * @inheritDoc
     */
    public function read(ProfileInterface $profile, callable $onHeader, callable $onRow): void
    {
        $options = $this->options($profile);
        $this->exported = [];
        $onHeader($this->columns($profile->getEntity(), $options['rows'] === 'order'));
        $connection = $this->resource->getConnection();
        $lastId = 0;
        while (true) {
            $select = $this->orders($profile, $options)->where('o.entity_id > ?', $lastId)->limit(self::PAGE);
            $orders = $connection->fetchAll($select);
            if ($orders === []) {
                break;
            }
            $ids = array_map(static fn (array $order): int => (int) $order['entity_id'], $orders);
            $lastId = max($ids);
            $addresses = $this->addresses($ids);
            $payments = $this->payments($ids);
            $shipments = $this->shipments($ids);
            $items = $options['rows'] === 'order' ? [] : $this->items($ids, $options['items'] === 'all');
            foreach ($orders as $order) {
                $id = (int) $order['entity_id'];
                $row = $this->orderRow($order, $addresses[$id] ?? [], $payments[$id] ?? [], $shipments[$id] ?? []);
                $exported = false;
                if ($options['rows'] === 'order') {
                    $exported = $onRow($row) !== false;
                } else {
                    foreach ($items[$id] ?? [] as $item) {
                        $exported = ($onRow($row + $item) !== false) || $exported;
                    }
                }
                if ($exported) {
                    $this->exported[] = $id;
                }
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function columns(string $entity, bool $orderRows = false): array
    {
        $columns = self::ORDER_COLUMNS;
        foreach (['billing', 'shipping'] as $kind) {
            foreach (self::ADDRESS_COLUMNS as $column) {
                $columns[] = $kind . '_' . $column;
            }
        }

        return $orderRows ? $columns : array_merge($columns, self::ITEM_COLUMNS);
    }

    /**
     * Records the orders that made it into the file, for "only new" and "new or changed" exports.
     *
     * @inheritDoc
     */
    public function finish(ProfileInterface $profile, int $runId): void
    {
        if ($this->exported !== []) {
            $this->marks->mark((int) $profile->getProfileId(), 'order', $this->exported, $runId);
        }
        $this->exported = [];
    }

    /**
     * @param ProfileInterface $profile
     * @return array<string, mixed> normalised export_options.order
     */
    public function options(ProfileInterface $profile): array
    {
        $raw = $profile->getExportOptions()['order'] ?? [];
        $raw = is_array($raw) ? $raw : [];
        $list = static fn (string $key): array => array_values(array_filter(array_map(
            'trim',
            explode(',', is_scalar($raw[$key] ?? null) ? (string) $raw[$key] : '')
        )));

        return [
            'rows' => ($raw['rows'] ?? 'item') === 'order' ? 'order' : 'item',
            'items' => ($raw['items'] ?? 'parents') === 'all' ? 'all' : 'parents',
            'only' => in_array($raw['only'] ?? 'all', ['new', 'new_or_changed'], true) ? (string) $raw['only'] : 'all',
            'statuses' => $list('statuses'),
            'store_ids' => array_map('intval', $list('store_ids')),
            'from' => $this->date($raw['from'] ?? null),
            'to' => $this->date($raw['to'] ?? null),
        ];
    }

    /**
     * @param mixed $value
     * @return string|null Y-m-d H:i:s or null
     */
    private function date(mixed $value): ?string
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable(trim((string) $value), new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        } catch (\Exception $exception) {
            throw new PipelineException(sprintf('"%s" is not a date.', (string) $value));
        }
    }

    /**
     * @param ProfileInterface $profile
     * @param array<string, mixed> $options
     * @return Select
     */
    private function orders(ProfileInterface $profile, array $options): Select
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['o' => $this->resource->getTableName('sales_order')])
            ->joinLeft(['s' => $this->resource->getTableName('store')], 's.store_id = o.store_id', ['store_code' => 's.code'])
            ->joinLeft(['g' => $this->resource->getTableName('customer_group')], 'g.customer_group_id = o.customer_group_id', ['customer_group' => 'g.customer_group_code'])
            ->order('o.entity_id ASC');
        if ($options['statuses'] !== []) {
            $select->where('o.status IN (?)', $options['statuses']);
        }
        if ($options['store_ids'] !== []) {
            $select->where('o.store_id IN (?)', $options['store_ids']);
        }
        if ($options['from'] !== null) {
            $select->where('o.created_at >= ?', $options['from']);
        }
        if ($options['to'] !== null) {
            $select->where('o.created_at <= ?', $options['to']);
        }
        if ($options['only'] !== 'all') {
            $marks = $this->resource->getTableName('openimport_export_mark');
            $select->joinLeft(
                ['m' => $marks],
                $connection->quoteInto('m.entity_id = o.entity_id AND m.entity_type = ? AND m.profile_id = ', 'order')
                    . (int) $profile->getProfileId(),
                []
            );
            $select->where(
                $options['only'] === 'new' ? 'm.entity_id IS NULL' : '(m.entity_id IS NULL OR o.updated_at > m.exported_at)'
            );
        }

        return $select;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, array<string, mixed>> $addresses by address_type
     * @param array<string, mixed> $payment
     * @param array<string, mixed> $shipments increment ids, tracking numbers, carriers
     * @return array<string, string>
     */
    private function orderRow(array $order, array $addresses, array $payment, array $shipments): array
    {
        $row = [];
        foreach (self::ORDER_COLUMNS as $column) {
            $row[$column] = match ($column) {
                'payment_method' => (string) ($payment['method'] ?? ''),
                'payment_last_trans_id' => (string) ($payment['last_trans_id'] ?? ''),
                'payment_po_number' => (string) ($payment['po_number'] ?? ''),
                'shipment_increment_ids', 'tracking_numbers', 'tracking_carriers' => (string) ($shipments[$column] ?? ''),
                default => $this->text($order[$column] ?? null),
            };
        }
        foreach (['billing', 'shipping'] as $kind) {
            $address = $addresses[$kind] ?? [];
            foreach (self::ADDRESS_COLUMNS as $column) {
                $value = $address[$column] ?? null;
                $row[$kind . '_' . $column] = $column === 'street'
                    ? str_replace("\n", ', ', $this->text($value))
                    : $this->text($value);
            }
        }

        return $row;
    }

    /**
     * @param mixed $value
     * @return string decimals without trailing zeros, dates as stored
     */
    private function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        $text = (string) $value;
        if (preg_match('/^-?\d+\.\d{4,}$/', $text) === 1) {
            $text = rtrim(rtrim($text, '0'), '.');
            if ($text === '-0') {
                $text = '0';
            }
        }

        return $text;
    }

    /**
     * @param int[] $orderIds
     * @return array<int, array<string, array<string, mixed>>> order id => address type => row
     */
    private function addresses(array $orderIds): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()->from($this->resource->getTableName('sales_order_address'))
                ->where('parent_id IN (?)', $orderIds)
        );
        $byOrder = [];
        foreach ($rows as $row) {
            $byOrder[(int) $row['parent_id']][(string) $row['address_type']] = $row;
        }

        return $byOrder;
    }

    /**
     * @param int[] $orderIds
     * @return array<int, array<string, mixed>>
     */
    private function payments(array $orderIds): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('sales_order_payment'), ['parent_id', 'method', 'last_trans_id', 'po_number'])
                ->where('parent_id IN (?)', $orderIds)
        );
        $byOrder = [];
        foreach ($rows as $row) {
            $byOrder[(int) $row['parent_id']] = $row;
        }

        return $byOrder;
    }

    /**
     * @param int[] $orderIds
     * @return array<int, array<string, string>>
     */
    private function shipments(array $orderIds): array
    {
        $connection = $this->resource->getConnection();
        $byOrder = [];
        $shipments = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('sales_shipment'), ['order_id', 'increment_id'])
                ->where('order_id IN (?)', $orderIds)
                ->order('entity_id ASC')
        );
        foreach ($shipments as $shipment) {
            $byOrder[(int) $shipment['order_id']]['shipment_increment_ids'][] = (string) $shipment['increment_id'];
        }
        $tracks = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('sales_shipment_track'), ['order_id', 'track_number', 'carrier_code'])
                ->where('order_id IN (?)', $orderIds)
                ->order('entity_id ASC')
        );
        foreach ($tracks as $track) {
            $byOrder[(int) $track['order_id']]['tracking_numbers'][] = (string) $track['track_number'];
            $byOrder[(int) $track['order_id']]['tracking_carriers'][] = (string) $track['carrier_code'];
        }
        $joined = [];
        foreach ($byOrder as $orderId => $lists) {
            foreach ($lists as $column => $values) {
                $joined[$orderId][$column] = implode(';', $values);
            }
        }

        return $joined;
    }

    /**
     * @param int[] $orderIds
     * @param bool $all child items too
     * @return array<int, array<int, array<string, string>>> order id => item rows
     */
    private function items(array $orderIds, bool $all): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('sales_order_item');
        $select = $connection->select()
            ->from(['i' => $table])
            ->joinLeft(['p' => $table], 'p.item_id = i.parent_item_id', ['item_parent_sku' => 'p.sku'])
            ->where('i.order_id IN (?)', $orderIds)
            ->order('i.item_id ASC');
        if (!$all) {
            $select->where('i.parent_item_id IS NULL');
        }
        $byOrder = [];
        foreach ($connection->fetchAll($select) as $item) {
            $row = [];
            foreach (self::ITEM_COLUMNS as $column) {
                $source = $column === 'item_parent_sku' ? 'item_parent_sku' : ($column === 'item_id' ? 'item_id' : substr($column, 5));
                $row[$column] = $this->text($item[$source] ?? null);
            }
            $byOrder[(int) $item['order_id']][] = $row;
        }

        return $byOrder;
    }
}
