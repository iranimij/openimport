<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Order;

use Iranimij\OpenImport\Model\Bridge\ImporterInterface;
use Iranimij\OpenImport\Model\Bridge\ImportRequest;
use Iranimij\OpenImport\Model\Bridge\ImportResult;
use Iranimij\OpenImport\Model\Bridge\RowError;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\AbstractSource;
use Magento\Sales\Api\Data\CreditmemoItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\InvoiceItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterfaceFactory;
use Magento\Sales\Api\InvoiceOrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\RefundOrderInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;
use Magento\Sales\Model\Order\AddressFactory;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\ItemFactory;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\PaymentFactory;
use Magento\Sales\Model\OrderFactory;
use Magento\SalesSequence\Model\Manager as SequenceManager;
use Psr\Log\LoggerInterface;

/**
 * Imports historic orders as they are: items, addresses, payment (recorded, never charged), state and status,
 * totals from the file; optionally the invoices, shipments and credit memos the quantities describe, through
 * Magento's own services; no stock change unless chosen; customers by e-mail (on the store's website) or guests.
 * One database transaction per order. Orders are only ever added: an order number that exists is skipped.
 *
 * Options (the profile's import options): order_number keep|generate, order_documents 0|1, order_stock 0|1.
 */
class OrderImporter implements ImporterInterface
{
    public const OPTION_NUMBER = 'order_number';
    public const OPTION_DOCUMENTS = 'order_documents';
    public const OPTION_STOCK = 'order_stock';

    /**
     * @param OrderRecords $records
     * @param OrderFile $file
     * @param OrderFactory $orderFactory
     * @param ItemFactory $itemFactory
     * @param AddressFactory $addressFactory
     * @param PaymentFactory $paymentFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param SequenceManager $sequences
     * @param ResourceConnection $resource
     * @param InvoiceOrderInterface $invoiceOrder
     * @param ShipOrderInterface $shipOrder
     * @param RefundOrderInterface $refundOrder
     * @param InvoiceItemCreationInterfaceFactory $invoiceItems
     * @param ShipmentItemCreationInterfaceFactory $shipmentItems
     * @param ShipmentTrackCreationInterfaceFactory $tracks
     * @param CreditmemoItemCreationInterfaceFactory $creditmemoItems
     * @param StockRegistryInterface $stockRegistry
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly OrderRecords $records,
        private readonly OrderFile $file,
        private readonly OrderFactory $orderFactory,
        private readonly ItemFactory $itemFactory,
        private readonly AddressFactory $addressFactory,
        private readonly PaymentFactory $paymentFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SequenceManager $sequences,
        private readonly ResourceConnection $resource,
        private readonly InvoiceOrderInterface $invoiceOrder,
        private readonly ShipOrderInterface $shipOrder,
        private readonly RefundOrderInterface $refundOrder,
        private readonly InvoiceItemCreationInterfaceFactory $invoiceItems,
        private readonly ShipmentItemCreationInterfaceFactory $shipmentItems,
        private readonly ShipmentTrackCreationInterfaceFactory $tracks,
        private readonly CreditmemoItemCreationInterfaceFactory $creditmemoItems,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function run(ImportRequest $request, AbstractSource $source): ImportResult
    {
        $started = microtime(true);
        $errors = [];
        if (!in_array($request->behavior, [Import::BEHAVIOR_APPEND, Import::BEHAVIOR_ADD_UPDATE], true)) {
            $errors[] = new RowError(null, 'behavior', 'Orders can only be added: use Add/Update.', null, true);
        }
        $orders = $this->file->group($source, $errors);
        $rows = 0;
        foreach ($orders as $order) {
            $rows = max($rows, max($order['rows']) + 1);
        }
        $rows = max($rows, $this->countErrorRows($errors));
        $generate = ($request->options[self::OPTION_NUMBER] ?? 'keep') === 'generate';
        $existing = $generate ? [] : $this->records->existingOrders(array_keys($orders));
        $statuses = $this->records->statuses();
        $groups = $this->records->groups();
        $ready = [];
        foreach ($orders as $key => &$order) {
            if (isset($existing[(string) $key])) {
                $errors[] = new RowError(
                    (int) $order['row'],
                    'skipped',
                    sprintf('Order %s exists already; it is not imported again.', $key),
                    'increment_id',
                    false
                );
                continue;
            }
            $store = $this->records->store($order['order']['store_code']);
            if ($store === null) {
                $errors[] = new RowError(
                    (int) $order['row'],
                    'store_code',
                    sprintf('Unknown store view "%s".', $order['order']['store_code']),
                    'store_code',
                    true
                );
                continue;
            }
            $order['store'] = $store;
            if ($this->file->check($order, $statuses, $groups, $errors)) {
                $ready[$key] = $order;
            }
        }
        unset($order);
        $critical = array_filter($errors, static fn (RowError $error): bool => $error->critical);
        $invalid = count(array_filter($critical, static fn (RowError $error): bool => $error->row !== null));
        $general = array_filter($critical, static fn (RowError $error): bool => $error->row === null) !== [];
        $valid = !$general && ($invalid === 0 || ($request->skipErrors && $invalid <= $request->allowedErrors));
        if ($request->dryRun || !$valid) {
            return $this->result($valid, false, $rows, $invalid, $valid ? count($ready) : 0, $errors, $started);
        }
        $created = 0;
        foreach ($ready as $key => $order) {
            $connection = $this->resource->getConnection();
            $connection->beginTransaction();
            try {
                $this->create((string) $key, $order, $generate, $request->options);
                $connection->commit();
                $created++;
            } catch (\Throwable $exception) {
                $connection->rollBack();
                $this->logger->error('OpenImport order import: ' . $exception->getMessage(), [
                    'exception' => $exception,
                ]);
                $errors[] = new RowError(
                    (int) $order['row'],
                    'save',
                    sprintf('Order %s: %s', $key, $exception->getMessage()),
                    null,
                    !$request->skipErrors
                );
                $invalid += count($order['rows']);
                if (!$request->skipErrors) {
                    return $this->result(false, $created > 0, $rows, $invalid, $created, $errors, $started);
                }
            }
        }

        return $this->result(true, true, $rows, $invalid, $created, $errors, $started);
    }

    /**
     * Creates one order with its items, addresses and payment, then its documents.
     *
     * @param string $key the order number in the file
     * @param array<string, mixed> $order
     * @param bool $generate a new order number from the store's sequence
     * @param array<string, mixed> $options
     * @return void
     */
    private function create(string $key, array $order, bool $generate, array $options): void
    {
        $data = $order['order'];
        $store = $order['store'];
        $statuses = $this->records->statuses();
        $status = $data['status'];
        $state = $data['state'] !== '' ? $data['state'] : (string) ($statuses[$status]['state'] ?? Order::STATE_NEW);
        if ($status === '') {
            $status = $this->records->defaultStatus($state) ?: $state;
        }
        $currency = $data['order_currency_code'] !== '' ? $data['order_currency_code'] : $store['currency'];
        $customer = $this->records->customers([$data['customer_email']], (int) $store['website_id'])[mb_strtolower($data['customer_email'])] ?? null;
        $isGuest = $customer === null || in_array(strtolower($data['customer_is_guest']), ['1', 'yes', 'true'], true);
        $groups = $this->records->groups();
        $groupId = $data['customer_group'] === ''
            ? ($isGuest || $customer === null ? 0 : $customer['group_id'])
            : ($groups[mb_strtolower($data['customer_group'])] ?? (int) $data['customer_group']);
        $incrementId = $generate
            ? (string) $this->sequences->getSequence('order', (int) $store['id'])->getNextValue()
            : $key;

        // With documents the order starts as new (a complete or closed order cannot be invoiced or shipped); the
        // file's state and status are set once the documents exist.
        $documents = ($options[self::OPTION_DOCUMENTS] ?? '0') === '1';
        /** @var Order $model */
        $model = $this->orderFactory->create();
        $model->setIncrementId($incrementId)
            ->setStoreId((int) $store['id'])
            ->setStoreName($store['name'])
            ->setState($documents ? Order::STATE_NEW : $state)
            ->setStatus($documents ? ($this->records->defaultStatus(Order::STATE_NEW) ?: 'pending') : $status)
            ->setCustomerIsGuest($isGuest ? 1 : 0)
            ->setCustomerId($isGuest ? null : $customer['id'])
            ->setCustomerEmail($data['customer_email'])
            ->setCustomerFirstname($data['customer_firstname'] !== '' ? $data['customer_firstname'] : $order['billing']['firstname'])
            ->setCustomerLastname($data['customer_lastname'] !== '' ? $data['customer_lastname'] : $order['billing']['lastname'])
            ->setCustomerGroupId($groupId)
            ->setOrderCurrencyCode($currency)
            ->setBaseCurrencyCode($currency)
            ->setStoreCurrencyCode($currency)
            ->setGlobalCurrencyCode($currency)
            ->setBaseToGlobalRate(1)
            ->setBaseToOrderRate(1)
            ->setStoreToBaseRate(1)
            ->setStoreToOrderRate(1)
            ->setShippingMethod($data['shipping_method'] !== '' ? $data['shipping_method'] : null)
            ->setShippingDescription($data['shipping_description'])
            ->setCustomerNote($data['customer_note'] !== '' ? $data['customer_note'] : null)
            ->setCouponCode($data['coupon_code'] !== '' ? $data['coupon_code'] : null)
            ->setDiscountDescription($data['discount_description'] !== '' ? $data['discount_description'] : null)
            ->setRemoteIp($data['remote_ip'] !== '' ? $data['remote_ip'] : null)
            ->setIsVirtual(0);
        if ($data['created_at'] !== '') {
            $model->setCreatedAt(gmdate('Y-m-d H:i:s', (int) strtotime($data['created_at'])));
        }
        foreach ([
            'subtotal' => 'subtotal', 'subtotal_incl_tax' => 'subtotal_incl_tax',
            'discount_amount' => 'discount_amount', 'shipping_amount' => 'shipping_amount',
            'shipping_incl_tax' => 'shipping_incl_tax', 'tax_amount' => 'tax_amount', 'grand_total' => 'grand_total',
            'total_paid' => 'total_paid', 'total_refunded' => 'total_refunded', 'total_due' => 'total_due',
        ] as $column => $field) {
            if ($data[$column] !== '') {
                $value = (float) $data[$column];
                if ($column === 'discount_amount') {
                    $value = -abs($value);
                }
                $model->setData($field, $value);
                $model->setData('base_' . $field, $value);
            }
        }
        $model->setTotalQtyOrdered((float) $data['total_qty_ordered']);
        if ($data['weight'] !== '') {
            $model->setWeight((float) $data['weight']);
        }

        $model->setBillingAddress($this->address($order['billing'], 'billing', $data['customer_email']));
        // Imported orders are never virtual, and Magento's order view needs a shipping address: without shipping
        // columns the billing address is used.
        $model->setShippingAddress($this->address(
            $this->filled($order['shipping']) ? $order['shipping'] : $order['billing'],
            'shipping',
            $data['customer_email']
        ));
        /** @var Payment $payment */
        $payment = $this->paymentFactory->create();
        $payment->setMethod($data['payment_method'] !== '' ? $data['payment_method'] : 'checkmo');
        if ($data['payment_last_trans_id'] !== '') {
            $payment->setLastTransId($data['payment_last_trans_id']);
        }
        if ($data['payment_po_number'] !== '') {
            $payment->setPoNumber($data['payment_po_number']);
        }
        $model->setPayment($payment);

        $items = $this->items($order, (int) $store['id']);
        if (!$documents) {
            // No documents: the items still carry the file's quantities, so the order reads as it was.
            foreach ($items as $item) {
                $item['model']->setQtyInvoiced((float) ($item['data']['item_qty_invoiced'] ?: 0))
                    ->setQtyShipped((float) ($item['data']['item_qty_shipped'] ?: 0))
                    ->setQtyRefunded((float) ($item['data']['item_qty_refunded'] ?: 0));
            }
        }
        // Parents first: a child is saved with its parent's id (Item::beforeSave reads the parent item).
        foreach ($items as $item) {
            if ($item['parent'] === null) {
                $model->addItem($item['model']);
            }
        }
        foreach ($items as $item) {
            if ($item['parent'] !== null) {
                $item['model']->setParentItem($items[$item['parent']]['model']);
                $model->addItem($item['model']);
            }
        }
        $this->orderRepository->save($model);
        $model->addStatusHistoryComment(sprintf('Imported by OpenImport (order %s in the file).', $key), false)
            ->setIsCustomerNotified(0);
        $this->orderRepository->save($model);

        if ($documents) {
            $this->documents($model, $order, $items);
            // The documents move the state; the file says where the order stands.
            $saved = $this->orderRepository->get((int) $model->getEntityId());
            if (!$saved instanceof Order) {
                throw new \RuntimeException('The order could not be loaded again.');
            }
            $model = $saved;
            $model->setState($state)->setStatus($status);
            foreach (['total_paid', 'total_refunded', 'total_due'] as $column) {
                if ($data[$column] !== '') {
                    $model->setData($column, (float) $data[$column]);
                    $model->setData('base_' . $column, (float) $data[$column]);
                }
            }
            $this->orderRepository->save($model);
        }
        if (($options[self::OPTION_STOCK] ?? '0') === '1') {
            $this->reduceStock($items);
        }
    }

    /**
     * @param array<string, mixed> $order
     * @param int $storeId
     * @return array<int, array{model: OrderItem, parent: int|null, sku: string, data: array<string, string>}>
     */
    private function items(array $order, int $storeId): array
    {
        $products = $this->records->products(array_column($order['items'], 'item_sku'));
        $bySku = [];
        foreach ($order['items'] as $index => $item) {
            $bySku[mb_strtolower($item['item_sku'])] = $index;
        }
        $items = [];
        foreach ($order['items'] as $index => $item) {
            $product = $products[mb_strtolower($item['item_sku'])] ?? null;
            $qty = (float) $item['item_qty_ordered'];
            $price = (float) $item['item_price'];
            $rowTotal = $item['item_row_total'] !== '' ? (float) $item['item_row_total'] : $price * $qty;
            $type = $item['item_product_type'] !== '' ? $item['item_product_type'] : ($product['type'] ?? 'simple');
            /** @var OrderItem $model */
            $model = $this->itemFactory->create();
            $model->setStoreId($storeId)
                ->setSku($item['item_sku'])
                ->setName($item['item_name'] !== '' ? $item['item_name'] : $item['item_sku'])
                ->setProductType($type)
                ->setProductId($product['id'] ?? null)
                ->setIsVirtual(0)
                ->setQtyOrdered($qty)
                ->setQtyInvoiced(0)
                ->setQtyShipped(0)
                ->setQtyRefunded(0)
                ->setQtyCanceled((float) ($item['item_qty_canceled'] ?: 0))
                ->setPrice($price)
                ->setBasePrice($price)
                ->setOriginalPrice($price)
                ->setBaseOriginalPrice($price)
                ->setRowTotal($rowTotal)
                ->setBaseRowTotal($rowTotal);
            foreach ([
                'item_price_incl_tax' => 'price_incl_tax', 'item_row_total_incl_tax' => 'row_total_incl_tax',
                'item_discount_amount' => 'discount_amount', 'item_tax_amount' => 'tax_amount',
            ] as $column => $field) {
                if ($item[$column] !== '') {
                    $model->setData($field, (float) $item[$column]);
                    $model->setData('base_' . $field, (float) $item[$column]);
                }
            }
            if ($item['item_tax_percent'] !== '') {
                $model->setTaxPercent((float) $item['item_tax_percent']);
            }
            if ($item['item_weight'] !== '') {
                $model->setWeight((float) $item['item_weight']);
            }
            if ($item['item_product_options'] !== '') {
                $options = json_decode($item['item_product_options'], true);
                if (is_array($options)) {
                    $model->setProductOptions($options);
                }
            }
            $parent = $item['item_parent_sku'] !== '' ? ($bySku[mb_strtolower($item['item_parent_sku'])] ?? null) : null;
            $items[$index] = ['model' => $model, 'parent' => $parent, 'sku' => $item['item_sku'], 'data' => $item];
        }

        return $items;
    }

    /**
     * Invoice, shipment and credit memo from the item quantities of the file, through Magento's own services.
     *
     * @param Order $model
     * @param array<string, mixed> $order
     * @param array<int, array{model: OrderItem, parent: int|null, sku: string, data: array<string, string>}> $items
     * @return void
     */
    private function documents(Order $model, array $order, array $items): void
    {
        $orderId = (int) $model->getEntityId();
        $quantities = ['item_qty_invoiced' => [], 'item_qty_shipped' => [], 'item_qty_refunded' => []];
        foreach ($items as $item) {
            if ($item['parent'] !== null) {
                continue;
            }
            $itemId = (int) $item['model']->getItemId();
            foreach (array_keys($quantities) as $column) {
                $qty = (float) ($item['data'][$column] ?: 0);
                if ($qty > 0) {
                    $quantities[$column][$itemId] = $qty;
                }
            }
        }
        $invoice = $quantities['item_qty_invoiced'];
        $ship = $quantities['item_qty_shipped'];
        $refund = $quantities['item_qty_refunded'];
        if ($invoice !== []) {
            $creations = [];
            foreach ($invoice as $itemId => $qty) {
                $creation = $this->invoiceItems->create();
                $creation->setOrderItemId($itemId);
                $creation->setQty($qty);
                $creations[] = $creation;
            }
            $this->invoiceOrder->execute($orderId, false, $creations, false, false);
        }
        if ($ship !== []) {
            $creations = [];
            foreach ($ship as $itemId => $qty) {
                $creation = $this->shipmentItems->create();
                $creation->setOrderItemId($itemId);
                $creation->setQty($qty);
                $creations[] = $creation;
            }
            $tracks = [];
            $numbers = array_filter(array_map('trim', explode(';', $order['order']['tracking_numbers'])));
            $carriers = array_map('trim', explode(';', $order['order']['tracking_carriers']));
            foreach (array_values($numbers) as $index => $number) {
                $track = $this->tracks->create();
                $code = $carriers[$index] ?? '';
                $track->setCarrierCode($code !== '' ? $code : 'custom');
                $track->setTitle($code !== '' ? ucfirst($code) : 'Custom');
                $track->setTrackNumber($number);
                $tracks[] = $track;
            }
            $this->shipOrder->execute($orderId, $creations, false, false, null, $tracks);
        }
        if ($refund !== []) {
            $creations = [];
            foreach ($refund as $itemId => $qty) {
                $creation = $this->creditmemoItems->create();
                $creation->setOrderItemId($itemId);
                $creation->setQty($qty);
                $creations[] = $creation;
            }
            $this->refundOrder->execute($orderId, $creations, false, false);
        }
    }

    /**
     * @param array<int, array{model: OrderItem, parent: int|null, sku: string, data: array<string, string>}> $items
     * @return void
     */
    private function reduceStock(array $items): void
    {
        foreach ($items as $item) {
            $type = (string) $item['model']->getProductType();
            if ($item['model']->getProductId() === null || !in_array($type, ['simple', 'virtual', 'downloadable'], true)) {
                continue;
            }
            try {
                $stock = $this->stockRegistry->getStockItemBySku($item['sku']);
                $qty = (float) $stock->getQty() - (float) $item['model']->getQtyOrdered();
                $stock->setQty($qty);
                if ($qty <= 0) {
                    $stock->setIsInStock(false);
                }
                $this->stockRegistry->updateStockItemBySku($item['sku'], $stock);
            } catch (\Exception $exception) {
                $this->logger->warning(sprintf(
                    'OpenImport order import: stock of %s not changed: %s',
                    $item['sku'],
                    $exception->getMessage()
                ));
            }
        }
    }

    /**
     * @param array<string, string> $data
     * @param string $type billing or shipping
     * @param string $email
     * @return OrderAddress
     */
    private function address(array $data, string $type, string $email): OrderAddress
    {
        /** @var OrderAddress $address */
        $address = $this->addressFactory->create();
        $address->setAddressType($type)
            ->setFirstname($data['firstname'])
            ->setLastname($data['lastname'])
            ->setCompany($data['company'] !== '' ? $data['company'] : null)
            ->setStreet(array_values(array_filter(array_map('trim', preg_split('/[,\n]/', $data['street']) ?: []))))
            ->setCity($data['city'])
            ->setPostcode($data['postcode'])
            ->setRegion($data['region'] !== '' ? $data['region'] : null)
            ->setCountryId($data['country_id'])
            ->setTelephone($data['telephone'] !== '' ? $data['telephone'] : '-')
            ->setEmail($data['email'] !== '' ? $data['email'] : $email)
            ->setVatId($data['vat_id'] !== '' ? $data['vat_id'] : null);

        return $address;
    }

    /**
     * @param array<string, string> $address
     * @return bool
     */
    private function filled(array $address): bool
    {
        return $address['firstname'] !== '' || $address['lastname'] !== '' || $address['street'] !== '';
    }

    /**
     * @param RowError[] $errors
     * @return int
     */
    private function countErrorRows(array $errors): int
    {
        $max = 0;
        foreach ($errors as $error) {
            $max = max($max, ($error->row ?? -1) + 1);
        }

        return $max;
    }

    /**
     * @param bool $valid
     * @param bool $imported
     * @param int $rows
     * @param int $invalid
     * @param int $created
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
        array $errors,
        float $started
    ): ImportResult {
        return new ImportResult($valid, $imported, $rows, $rows, $invalid, $created, 0, 0, $errors, null, microtime(true) - $started);
    }
}
