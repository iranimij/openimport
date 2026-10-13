<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Ui\Component\Listing\Column;

use Iranimij\OpenImport\Model\Config\Source\Entity;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Entity codes in plain names ("Products", "Stock & prices" …).
 */
class EntityName extends Column
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param Entity $entities
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly Entity $entities,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @param array<string, mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource)
    {
        $names = [];
        foreach ($this->entities->toOptionArray() as $option) {
            $names[$option['direction'] . ':' . $option['value']] = (string) $option['label'];
        }
        foreach ($dataSource['data']['items'] ?? [] as &$item) {
            $key = ($item['direction'] ?? 'import') . ':' . ($item['entity'] ?? '');
            $item['entity'] = $names[$key] ?? (string) ($item['entity'] ?? '');
        }

        return $dataSource;
    }
}
