<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Configurable;

use Magento\Framework\Filter\TranslitUrl;

/**
 * URL keys the way Magento makes them from a product name (transliterated, lower case, hyphens).
 */
class UrlKey
{
    /**
     * @param TranslitUrl $translitUrl
     */
    public function __construct(
        private readonly TranslitUrl $translitUrl
    ) {
    }

    /**
     * @param string $text
     * @return string
     */
    public function format(string $text): string
    {
        return trim((string) $this->translitUrl->filter($text), '-');
    }
}
