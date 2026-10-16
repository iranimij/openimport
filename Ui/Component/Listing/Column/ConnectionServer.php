<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Ui\Component\Listing\Column;

use Iranimij\OpenImport\Model\JsonField;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * "user@host:port" of a connection, from its settings (never the secrets).
 */
class ConnectionServer extends Column
{
    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param JsonField $json
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly JsonField $json,
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
        foreach ($dataSource['data']['items'] as &$item) {
            $settings = $this->json->decode($item['settings'] ?? null);
            $host = is_scalar($settings['host'] ?? null) ? (string) $settings['host'] : '';
            $user = is_scalar($settings['username'] ?? null) ? (string) $settings['username'] : '';
            $port = is_scalar($settings['port'] ?? null) ? (string) $settings['port'] : '';
            $item[$this->getData('name')] = ($user !== '' ? $user . '@' : '') . $host . ($port !== '' ? ':' . $port : '');
            unset($item['settings']);
        }

        return $dataSource;
    }
}
