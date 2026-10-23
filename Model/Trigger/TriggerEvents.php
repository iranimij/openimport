<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Trigger;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * The shop events a profile can run after (step 6 › "Also run when").
 */
class TriggerEvents implements OptionSourceInterface
{
    public const PRODUCT_SAVED = 'product_saved';
    public const STOCK_CHANGED = 'stock_changed';
    public const CUSTOMER_REGISTERED = 'customer_registered';
    public const CUSTOMER_SAVED = 'customer_saved';
    public const ORDER_PLACED = 'order_placed';
    public const ORDER_STATUS_CHANGED = 'order_status_changed';

    /**
     * Event => label, in the order the form offers them.
     */
    public const LABELS = [
        self::PRODUCT_SAVED => 'A product is saved',
        self::STOCK_CHANGED => 'Stock changes',
        self::CUSTOMER_REGISTERED => 'A customer registers',
        self::CUSTOMER_SAVED => 'A customer is saved',
        self::ORDER_PLACED => 'An order is placed',
        self::ORDER_STATUS_CHANGED => 'An order changes status',
    ];

    /**
     * Short words for the run's "Started by" ("Event · product saved").
     */
    public const SHORT = [
        self::PRODUCT_SAVED => 'product saved',
        self::STOCK_CHANGED => 'stock changed',
        self::CUSTOMER_REGISTERED => 'customer registered',
        self::CUSTOMER_SAVED => 'customer saved',
        self::ORDER_PLACED => 'order placed',
        self::ORDER_STATUS_CHANGED => 'order status changed',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach (self::LABELS as $value => $label) {
            $options[] = ['value' => $value, 'label' => __($label)];
        }

        return $options;
    }
}
