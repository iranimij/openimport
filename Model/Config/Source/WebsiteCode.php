<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Websites by code (as Magento's import names them), "Name (code)".
 */
class WebsiteCode implements OptionSourceInterface
{
    /**
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $code = (string) $website->getCode();
            $options[] = ['value' => $code, 'label' => sprintf('%s (%s)', (string) $website->getName(), $code)];
        }

        return $options;
    }
}
