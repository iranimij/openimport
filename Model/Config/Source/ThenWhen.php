<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Config\Source;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * When the next profile of a chain runs.
 */
class ThenWhen implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => ProfileInterface::THEN_SUCCESS, 'label' => __('Only after a successful run')],
            ['value' => ProfileInterface::THEN_ALWAYS, 'label' => __('Always, even after a failed run')],
        ];
    }
}
