<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Shipment;

use Iranimij\OpenImport\Model\Bridge\ImporterInterface;
use Iranimij\OpenImport\Model\Bridge\ImportRequest;
use Iranimij\OpenImport\Model\Bridge\ImportResult;
use Iranimij\OpenImport\Model\Bridge\RowError;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\AbstractSource;
use Magento\Sales\Api\Data\ShipmentCommentCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterfaceFactory;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Ships orders from a file through Magento's own ShipOrderInterface (stock, order state, e-mails and the shipment
 * history exactly as the admin's "Ship" button).
 *
 * One row per order and tracking number: order number, optional item SKU and quantity (empty = everything that is
 * still open), carrier, title, tracking number, notify customer, comment. Rows of the same order are shipped
 * together as one shipment with all their items and tracking numbers.
 *
 * Every row is checked first (order found, can ship, items open, quantities within what is left, carrier known);
 * on a dry run nothing is written. Orders already carrying the tracking number are skipped, so a file can be
 * imported twice without a second shipment. Only "Add/Update" and "Only add" make sense: there is nothing to
 * replace or delete.
 */
class ShipmentImporter implements ImporterInterface
{
    public const COLUMNS = ['increment_id', 'item_sku', 'qty', 'carrier_code', 'title', 'tracking_number',
        'notify', 'comment'];

    /**
     * @param ShipmentRecords $records
     * @param ShipOrderInterface $shipOrder
     * @param ShipmentItemCreationInterfaceFactory $itemFactory
     * @param ShipmentTrackCreationInterfaceFactory $trackFactory
     * @param ShipmentCommentCreationInterfaceFactory $commentFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ShipmentRecords $records,
        private readonly ShipOrderInterface $shipOrder,
        private readonly ShipmentItemCreationInterfaceFactory $itemFactory,
        private readonly ShipmentTrackCreationInterfaceFactory $trackFactory,
        private readonly ShipmentCommentCreationInterfaceFactory $commentFactory,
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
        $this->records->reset();
        if (!in_array($request->behavior, [Import::BEHAVIOR_APPEND, Import::BEHAVIOR_ADD_UPDATE], true)) {
            $errors[] = new RowError(null, 'behavior', 'Shipments can only be added: use Add/Update.', null, true);
        }
        $carriers = $this->records->carriers();
        /** @var array<string, array<string, mixed>> $shipments rows, items, tracks, notify, comment, skip per order */
        $shipments = [];
        $position = 0;
        foreach ($source as $row) {
            $this->collect($row, $position, $carriers, $shipments, $errors);
            $position++;
        }
        $this->checkQuantities($shipments, $errors);
        foreach ($shipments as $shipment) {
            if ($shipment['skip'] !== null) {
                // Reported, not refused: a file imported twice makes no second shipment.
                $errors[] = new RowError($shipment['rows'][0], 'skipped', $shipment['skip'], 'increment_id', false);
            }
        }
        $critical = array_filter($errors, static fn (RowError $error): bool => $error->critical);
        $invalid = count(array_filter($critical, static fn (RowError $error): bool => $error->row !== null));
        $general = array_filter($critical, static fn (RowError $error): bool => $error->row === null) !== [];
        $valid = !$general && ($invalid === 0 || ($request->skipErrors && $invalid <= $request->allowedErrors));
        $ready = array_filter(
            $shipments,
            fn (array $shipment): bool => $shipment['skip'] === null && !$this->hasError($shipment['rows'], $critical)
        );
        if ($request->dryRun || !$valid) {
            return $this->result($valid, false, $position, $invalid, $valid ? count($ready) : 0, $errors, $started);
        }
        $created = 0;
        foreach ($ready as $incrementId => $shipment) {
            try {
                $this->ship($this->records->order((string) $incrementId), $shipment);
                $created++;
            } catch (\Throwable $exception) {
                $this->logger->error('OpenImport shipment import: ' . $exception->getMessage(), [
                    'exception' => $exception,
                ]);
                $errors[] = new RowError(
                    $shipment['rows'][0],
                    'ship',
                    $exception->getMessage(),
                    null,
                    !$request->skipErrors
                );
                $invalid += count($shipment['rows']);
                if (!$request->skipErrors) {
                    return $this->result(false, $created > 0, $position, $invalid, $created, $errors, $started);
                }
            }
        }

        return $this->result(true, true, $position, $invalid, $created, $errors, $started);
    }

    /**
     * Checks one row and adds it to its order's shipment.
     *
     * @param array<string, mixed> $row
     * @param int $position
     * @param array<string, string> $carriers code => title
     * @param array<string, array<string, mixed>> $shipments
     * @param RowError[] $errors
     * @return void
     */
    private function collect(array $row, int $position, array $carriers, array &$shipments, array &$errors): void
    {
        $incrementId = trim((string) ($row['increment_id'] ?? ''));
        if ($incrementId === '') {
            $errors[] = new RowError($position, 'increment_id', 'The order number is empty.', 'increment_id', true);

            return;
        }
        $order = $this->records->order($incrementId);
        if ($order === null) {
            $message = sprintf('Order %s does not exist.', $incrementId);
            $errors[] = new RowError($position, 'increment_id', $message, 'increment_id', true);

            return;
        }
        $sku = trim((string) ($row['item_sku'] ?? ''));
        $qtyText = trim((string) ($row['qty'] ?? ''));
        $qty = null;
        if ($qtyText !== '') {
            if (!is_numeric($qtyText) || (float) $qtyText <= 0) {
                $errors[] = new RowError($position, 'qty', sprintf('"%s" is not a quantity.', $qtyText), 'qty', true);

                return;
            }
            if ($sku === '') {
                $errors[] = new RowError($position, 'qty', 'A quantity needs an item SKU.', 'item_sku', true);

                return;
            }
            $qty = (float) $qtyText;
        }
        $number = trim((string) ($row['tracking_number'] ?? ''));
        $code = trim((string) ($row['carrier_code'] ?? ''));
        $title = trim((string) ($row['title'] ?? ''));
        if ($number !== '') {
            if ($code === '') {
                $code = 'custom';
            }
            if (!isset($carriers[$code]) && $code !== 'custom') {
                $errors[] = new RowError(
                    $position,
                    'carrier_code',
                    sprintf('Unknown carrier "%s". Carriers: custom, %s.', $code, implode(', ', array_keys($carriers))),
                    'carrier_code',
                    true
                );

                return;
            }
            $title = $title !== '' ? $title : ($carriers[$code] ?? ucfirst($code));
        }
        if (!isset($shipments[$incrementId])) {
            $skip = null;
            if (!$order->canShip()) {
                $skip = sprintf('Order %s cannot be shipped (%s).', $incrementId, $this->why($order));
            } elseif ($number !== '' && $this->records->hasTrack($order, $number)) {
                $skip = sprintf('Order %s already has tracking number %s.', $incrementId, $number);
            }
            $shipments[$incrementId] = ['rows' => [], 'items' => [], 'tracks' => [], 'notify' => false,
                'comment' => '', 'skip' => $skip];
        }
        $shipment = &$shipments[$incrementId];
        $shipment['rows'][] = $position;
        if ($sku !== '') {
            $item = $this->records->shippableItem($order, $sku);
            if ($item === null) {
                $errors[] = new RowError(
                    $position,
                    'item_sku',
                    sprintf('Order %s has no open item with SKU "%s".', $incrementId, $sku),
                    'item_sku',
                    true
                );
            } else {
                $itemId = (int) $item->getItemId();
                $shipment['items'][$itemId] = $qty === null ? null : (float) ($shipment['items'][$itemId] ?? 0) + $qty;
            }
        }
        if ($number !== '') {
            $shipment['tracks'][] = ['code' => $code, 'title' => $title, 'number' => $number];
        }
        $notify = strtolower(trim((string) ($row['notify'] ?? '')));
        $shipment['notify'] = $shipment['notify'] || in_array($notify, ['1', 'yes', 'true', 'ja'], true);
        $comment = trim((string) ($row['comment'] ?? ''));
        if ($comment !== '' && $shipment['comment'] === '') {
            $shipment['comment'] = $comment;
        }
    }

    /**
     * Quantities within what is left to ship; rows without a SKU take everything open.
     *
     * @param array<string, array<string, mixed>> $shipments
     * @param RowError[] $errors
     * @return void
     */
    private function checkQuantities(array &$shipments, array &$errors): void
    {
        foreach ($shipments as $incrementId => &$shipment) {
            if ($shipment['skip'] !== null) {
                continue;
            }
            $order = $this->records->order((string) $incrementId);
            if ($order === null) {
                continue;
            }
            if ($shipment['items'] === []) {
                foreach ($this->records->shippableItems($order) as $item) {
                    $shipment['items'][(int) $item->getItemId()] = (float) $item->getQtyToShip();
                }
                continue;
            }
            foreach ($shipment['items'] as $itemId => $qty) {
                $item = $this->records->item($order, (int) $itemId);
                $open = $item === null ? 0.0 : (float) $item->getQtyToShip();
                if ($qty === null) {
                    $shipment['items'][$itemId] = $open;
                } elseif ($qty > $open + 0.00001) {
                    $errors[] = new RowError(
                        $shipment['rows'][0],
                        'qty',
                        sprintf(
                            'Order %s: %s of "%s" to ship, but only %s left.',
                            $incrementId,
                            $this->number($qty),
                            $item === null ? (string) $itemId : (string) $item->getSku(),
                            $this->number($open)
                        ),
                        'qty',
                        true
                    );
                }
            }
        }
    }

    /**
     * @param Order|null $order
     * @param array<string, mixed> $shipment
     * @return void
     */
    private function ship(?Order $order, array $shipment): void
    {
        if ($order === null) {
            throw new \RuntimeException('The order disappeared while importing.');
        }
        $items = [];
        foreach ($shipment['items'] as $itemId => $qty) {
            if ((float) $qty <= 0) {
                continue;
            }
            $item = $this->itemFactory->create();
            $item->setOrderItemId((int) $itemId);
            $item->setQty((float) $qty);
            $items[] = $item;
        }
        $tracks = [];
        foreach ($shipment['tracks'] as $track) {
            $creation = $this->trackFactory->create();
            $creation->setCarrierCode($track['code']);
            $creation->setTitle($track['title']);
            $creation->setTrackNumber($track['number']);
            $tracks[] = $creation;
        }
        $comment = null;
        if ($shipment['comment'] !== '') {
            $comment = $this->commentFactory->create();
            $comment->setComment($shipment['comment']);
            $comment->setIsVisibleOnFront(0);
        }
        $this->shipOrder->execute(
            (int) $order->getEntityId(),
            $items,
            (bool) $shipment['notify'],
            $comment !== null,
            $comment,
            $tracks
        );
    }

    /**
     * @param Order $order
     * @return string
     */
    private function why(Order $order): string
    {
        if ($order->isCanceled()) {
            return 'it is cancelled';
        }
        if ($order->getState() === Order::STATE_HOLDED) {
            return 'it is on hold';
        }
        if ($order->getState() === Order::STATE_COMPLETE) {
            return 'it is complete';
        }

        return $order->getState() === Order::STATE_CLOSED ? 'it is closed' : 'nothing is left to ship';
    }

    /**
     * @param int[] $rows
     * @param RowError[] $errors
     * @return bool
     */
    private function hasError(array $rows, array $errors): bool
    {
        foreach ($errors as $error) {
            if ($error->row !== null && in_array($error->row, $rows, true)) {
                return true;
            }
        }

        return false;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    /**
     * @param bool $valid
     * @param bool $imported
     * @param int $rows
     * @param int $invalid
     * @param int $created shipments made (or, on a dry run, that would be made)
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
        return new ImportResult(
            $valid,
            $imported,
            $rows,
            $rows,
            $invalid,
            $created,
            0,
            0,
            $errors,
            null,
            microtime(true) - $started
        );
    }
}
