<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\I18n;

use Magento\Framework\TranslateInterface;

/**
 * The phrases of the language the current request shows (the admin's interface language in the admin).
 */
class Translation
{
    /**
     * @param TranslateInterface $translate
     */
    public function __construct(private readonly TranslateInterface $translate)
    {
    }

    /**
     * Whether texts stored in English need translating: the language is not English.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return !str_starts_with((string) $this->translate->getLocale(), 'en_');
    }

    /**
     * The translation of a phrase, or null when the language has none.
     *
     * @param string $phrase
     * @return string|null
     */
    public function of(string $phrase): ?string
    {
        $translated = $this->translate->getData()[$phrase] ?? null;

        return is_string($translated) ? $translated : null;
    }
}
