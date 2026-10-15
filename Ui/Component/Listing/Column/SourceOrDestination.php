<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Ui\Component\Listing\Column;

use Iranimij\OpenImport\Model\Config\Source\Destination;
use Iranimij\OpenImport\Model\Config\Source\SourceType;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Where an import reads from, or where an export goes to.
 */
class SourceOrDestination extends Column
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param SourceType $sources
     * @param Destination $destinations
     * @param Json $json
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly SourceType $sources,
        private readonly Destination $destinations,
        private readonly Json $json,
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
        if (!isset($dataSource['data']['items']) || !is_array($dataSource['data']['items'])) {
            return $dataSource;
        }
        $sources = $this->labels($this->sources->toOptionArray());
        $destinations = $this->labels($this->destinations->toOptionArray());
        // By reference into the array itself (a "?? []" expression would be a copy).
        foreach ($dataSource['data']['items'] as &$item) {
            if (($item['direction'] ?? 'import') === 'export') {
                $type = (string) ($this->options($item['export_options'] ?? null)['destination'] ?? 'download');
                $item['source_type'] = '→ ' . ($destinations[$type] ?? $type);
            } else {
                $type = (string) ($item['source_type'] ?? '');
                $item['source_type'] = $sources[$type] ?? $type;
            }
        }

        return $dataSource;
    }

    /**
     * @param array<int, array<string, mixed>> $options
     * @return array<string, string>
     */
    private function labels(array $options): array
    {
        $labels = [];
        foreach ($options as $option) {
            $labels[(string) $option['value']] = (string) $option['label'];
        }

        return $labels;
    }

    /**
     * @param mixed $value JSON column (string) or already decoded
     * @return array<string, mixed>
     */
    private function options(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        try {
            $decoded = $this->json->unserialize((string) $value);
        } catch (\InvalidArgumentException $exception) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
