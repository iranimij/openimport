<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\ViewModel;

use Iranimij\OpenImport\Model\Preset\PresetRepository;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * The presets offered on the "Import profiles from JSON" page.
 */
class Presets implements ArgumentInterface
{
    /**
     * @param PresetRepository $presets
     */
    public function __construct(
        private readonly PresetRepository $presets
    ) {
    }

    /**
     * @return array<string, array{label: string, description: string, notes: string}>
     */
    public function all(): array
    {
        return $this->presets->all();
    }
}
