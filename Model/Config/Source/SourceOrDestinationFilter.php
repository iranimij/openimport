<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * The profiles grid's "Source / destination" filter: where imports read from and where exports go to.
 * Values are "in:<source type>" and "out:<destination>" (see the profiles grid collection).
 */
class SourceOrDestinationFilter implements OptionSourceInterface
{
    public const IMPORT_PREFIX = 'in:';
    public const EXPORT_PREFIX = 'out:';

    /**
     * @param SourceType $sources
     * @param Destination $destinations
     */
    public function __construct(
        private readonly SourceType $sources,
        private readonly Destination $destinations
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->sources->toOptionArray() as $option) {
            $options[] = [
                'value' => self::IMPORT_PREFIX . $option['value'],
                'label' => __('Import from: %1', $option['label']),
            ];
        }
        foreach ($this->destinations->toOptionArray() as $option) {
            $options[] = [
                'value' => self::EXPORT_PREFIX . $option['value'],
                'label' => __('Export to: %1', $option['value'] === 'download' ? __('Download') : $option['label']),
            ];
        }

        return $options;
    }
}
