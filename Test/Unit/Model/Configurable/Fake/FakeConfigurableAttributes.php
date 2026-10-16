<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Configurable\Fake;

use Iranimij\OpenImport\Model\Configurable\ConfigurableAttributes;

/**
 * "color" (Red, Blue, Green, Black) and "size" (S, M, L) can make variants; "material" is store-view scoped; nothing
 * else exists.
 */
class FakeConfigurableAttributes extends ConfigurableAttributes
{
    // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
    public function __construct()
    {
    }

    /**
     * @inheritDoc
     */
    public function eligible(): array
    {
        return ['color' => 'Color', 'size' => 'Size'];
    }

    /**
     * @inheritDoc
     */
    public function hasOption(string $code, string $label): bool
    {
        $options = ['color' => ['red', 'blue', 'green', 'black'], 'size' => ['s', 'm', 'l']];

        return in_array(mb_strtolower(trim($label)), $options[$code] ?? [], true);
    }

    /**
     * @inheritDoc
     */
    public function problem(string $code): ?string
    {
        return match ($code) {
            'color', 'size' => null,
            'material' => '"material" has no global scope; Magento needs global scope for variants.',
            default => sprintf('There is no product attribute "%s".', $code),
        };
    }
}
