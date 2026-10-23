<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Shipment;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use Magento\Shipping\Model\Config as CarrierConfig;

/**
 * Orders by number, their open items and tracking numbers, and the shop's carriers; orders are loaded once per run.
 */
class ShipmentRecords
{
    /**
     * @var array<string, Order|null>
     */
    private array $orders = [];

    /**
     * @var array<string, string>|null
     */
    private ?array $carriers = null;

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $criteria
     * @param CarrierConfig $carrierConfig
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $criteria,
        private readonly CarrierConfig $carrierConfig
    ) {
    }

    /**
     * @param string $incrementId
     * @return Order|null
     */
    public function order(string $incrementId): ?Order
    {
        if (!array_key_exists($incrementId, $this->orders)) {
            $list = $this->orderRepository->getList(
                $this->criteria->addFilter('increment_id', $incrementId)->setPageSize(1)->create()
            );
            $found = null;
            foreach ($list->getItems() as $order) {
                $found = $order instanceof Order ? $order : null;
            }
            $this->orders[$incrementId] = $found;
        }

        return $this->orders[$incrementId];
    }

    /**
     * Forgets the loaded orders (after shipping, the quantities changed).
     *
     * @return void
     */
    public function reset(): void
    {
        $this->orders = [];
    }

    /**
     * Items with something left to ship (no children of configurables or bundles: their parents are shipped).
     *
     * @param Order $order
     * @return Item[]
     */
    public function shippableItems(Order $order): array
    {
        $items = [];
        foreach ($order->getAllItems() as $item) {
            if ($item instanceof Item && $item->getParentItemId() === null && !$item->isDummy(true)
                && (float) $item->getQtyToShip() > 0) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param Order $order
     * @param string $sku
     * @return Item|null the open item with this SKU (a child's SKU finds its parent)
     */
    public function shippableItem(Order $order, string $sku): ?Item
    {
        $wanted = mb_strtolower($sku);
        $shippable = $this->shippableItems($order);
        foreach ($shippable as $item) {
            if (mb_strtolower((string) $item->getSku()) === $wanted) {
                return $item;
            }
        }
        foreach ($order->getAllItems() as $item) {
            if ($item instanceof Item && $item->getParentItemId() !== null
                && mb_strtolower((string) $item->getSku()) === $wanted) {
                foreach ($shippable as $parent) {
                    if ((int) $parent->getItemId() === (int) $item->getParentItemId()) {
                        return $parent;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param Order $order
     * @param int $itemId
     * @return Item|null
     */
    public function item(Order $order, int $itemId): ?Item
    {
        foreach ($order->getAllItems() as $item) {
            if ($item instanceof Item && (int) $item->getItemId() === $itemId) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param Order $order
     * @param string $number
     * @return bool a shipment of the order already carries this tracking number
     */
    public function hasTrack(Order $order, string $number): bool
    {
        foreach ($order->getTracksCollection() as $track) {
            if (strcasecmp(trim((string) $track->getTrackNumber()), $number) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string> carrier code => title, every carrier the shop has (active or not)
     */
    public function carriers(): array
    {
        if ($this->carriers === null) {
            $this->carriers = [];
            foreach ($this->carrierConfig->getAllCarriers() as $code => $carrier) {
                $title = $carrier->getConfigData('title');
                $this->carriers[(string) $code] = is_string($title) && $title !== '' ? $title : (string) $code;
            }
            ksort($this->carriers);
        }

        return $this->carriers;
    }
}
