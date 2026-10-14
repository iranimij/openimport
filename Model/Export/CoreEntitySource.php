<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Export;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Magento\Framework\Exception\LocalizedException;
use Magento\ImportExport\Model\Export;
use Magento\ImportExport\Model\Export\AbstractEntity as LegacyEntity;
use Magento\ImportExport\Model\Export\ConfigInterface as ExportConfig;
use Magento\ImportExport\Model\Export\Entity\AbstractEntity as EavEntity;
use Magento\ImportExport\Model\Export\Entity\Factory as EntityFactory;

/**
 * Magento's own export entities (export.xml: products, customers, addresses, advanced pricing, stock sources, …)
 * read through their entity adapter with OpenImport's capture writer, in the same process (no message queue).
 *
 * Profile export_options.core_filters: attribute code => value as the admin export form sends it (a text for
 * "like", a value for selects, [from, to] for dates and numbers); OpenImport's own row filters run on top.
 */
class CoreEntitySource implements EntitySourceInterface
{
    /**
     * @param ExportConfig $config
     * @param EntityFactory $entityFactory
     * @param CaptureAdapterFactory $captureFactory
     */
    public function __construct(
        private readonly ExportConfig $config,
        private readonly EntityFactory $entityFactory,
        private readonly CaptureAdapterFactory $captureFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function read(ProfileInterface $profile, callable $onHeader, callable $onRow): void
    {
        $adapter = $this->adapter($profile->getEntity());
        $options = $profile->getExportOptions();
        $filters = is_array($options['core_filters'] ?? null) ? $options['core_filters'] : [];
        $adapter->setParameters([
            'entity' => $profile->getEntity(),
            Export::FILTER_ELEMENT_GROUP => $filters,
            Export::FILTER_ELEMENT_SKIP => [],
        ]);
        $capture = $this->captureFactory->create(['destination' => 'openimport-capture']);
        $capture->listen($onHeader, $onRow);
        $adapter->setWriter($capture);
        try {
            $adapter->export();
        } catch (LocalizedException $exception) {
            throw new PipelineException('Magento could not export: ' . $exception->getMessage(), 0, $exception);
        }
        $this->checkErrors($adapter);
    }

    /**
     * @inheritDoc
     */
    public function columns(string $entity): array
    {
        try {
            $adapter = $this->adapter($entity);
        } catch (PipelineException $exception) {
            return [];
        }
        $columns = [];
        foreach ($adapter->getAttributeCollection() as $attribute) {
            $columns[] = (string) $attribute->getAttributeCode();
        }

        return array_values(array_unique(array_merge($this->permanentColumns($entity), $columns)));
    }

    /**
     * Columns core adds in front of the attributes; a best effort per entity for the mapping editor.
     *
     * @param string $entity
     * @return string[]
     */
    private function permanentColumns(string $entity): array
    {
        return match ($entity) {
            'catalog_product' => ['sku', 'store_view_code', 'attribute_set_code', 'product_type', 'categories',
                'product_websites', 'product_online', 'qty', 'is_in_stock', 'additional_attributes',
                'related_skus', 'crosssell_skus', 'upsell_skus', 'additional_images', 'configurable_variations'],
            'customer', 'customer_composite' => ['email', '_website', '_store'],
            'customer_address' => ['_website', '_email', '_entity_id'],
            'advanced_pricing' => ['sku', 'tier_price_website', 'tier_price_customer_group', 'tier_price_qty',
                'tier_price', 'tier_price_value_type'],
            'stock_sources' => ['source_code', 'sku', 'status', 'quantity'],
            default => [],
        };
    }

    /**
     * @param string $entity
     * @return EavEntity|LegacyEntity
     * @throws PipelineException
     */
    private function adapter(string $entity): EavEntity|LegacyEntity
    {
        $entities = $this->config->getEntities();
        $model = $entities[$entity]['model'] ?? null;
        if (!is_string($model) || $model === '') {
            throw new PipelineException(sprintf('Magento has no export entity "%s".', $entity));
        }
        try {
            $adapter = $this->entityFactory->create($model);
        } catch (\Throwable $exception) {
            throw new PipelineException(sprintf('The export entity "%s" could not be created: %s', $entity, $exception->getMessage()));
        }
        if (!$adapter instanceof EavEntity && !$adapter instanceof LegacyEntity) {
            throw new PipelineException(sprintf('The export entity "%s" is not a Magento export entity.', $entity));
        }

        return $adapter;
    }

    /**
     * Core entities collect some problems instead of throwing.
     *
     * @param EavEntity|LegacyEntity $adapter
     * @return void
     * @throws PipelineException
     */
    private function checkErrors(EavEntity|LegacyEntity $adapter): void
    {
        $messages = $adapter->getErrorMessages();
        if ($messages === []) {
            return;
        }
        $lines = [];
        foreach ($messages as $message => $rows) {
            $lines[] = sprintf('%s (rows %s)', (string) $message, implode(', ', (array) $rows));
        }
        throw new PipelineException("Magento reported problems while exporting:\n" . implode("\n", $lines));
    }
}
