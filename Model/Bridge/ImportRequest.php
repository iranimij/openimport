<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Bridge;

use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregatorInterface;

/**
 * What OpenImport asks core's import engine to do with one prepared source.
 */
class ImportRequest
{
    /**
     * @param string $entity core entity code (catalog_product, customer, …)
     * @param string $behavior Import::BEHAVIOR_* (append = Add/Update, replace, delete)
     * @param bool $dryRun validate only; nothing is written and no bunch stays behind
     * @param bool $skipErrors import valid rows when some rows are invalid (up to $allowedErrors)
     * @param int $allowedErrors errors tolerated before the whole import is refused
     * @param string $multipleValueSeparator separator of multi-value cells (categories, websites, …)
     * @param string $emptyValueConstant cell value that clears an attribute
     * @param string $imagesDirectory folder core reads pictures from: absolute, or relative to the Magento root
     *                                ('' = core's default, pub/media/import)
     * @param string $historyLabel name shown in core's System › Data Transfer › Import History
     * @param array<string, mixed> $options the profile's import options, for OpenImport's own importers
     */
    public function __construct(
        public readonly string $entity,
        public readonly string $behavior = Import::BEHAVIOR_APPEND,
        public readonly bool $dryRun = false,
        public readonly bool $skipErrors = false,
        public readonly int $allowedErrors = 0,
        public readonly string $multipleValueSeparator = Import::DEFAULT_GLOBAL_MULTI_VALUE_SEPARATOR,
        public readonly string $emptyValueConstant = Import::DEFAULT_EMPTY_ATTRIBUTE_VALUE_CONSTANT,
        public readonly string $imagesDirectory = '',
        public readonly string $historyLabel = 'openimport.csv',
        public readonly array $options = []
    ) {
    }

    /**
     * Data for core's import model (the same keys the admin import form posts).
     *
     * @return array<string, mixed>
     */
    public function toImportData(): array
    {
        $data = [
            'entity' => $this->entity,
            'behavior' => $this->behavior,
            Import::FIELD_NAME_VALIDATION_STRATEGY => $this->skipErrors
                ? ProcessingErrorAggregatorInterface::VALIDATION_STRATEGY_SKIP_ERRORS
                : ProcessingErrorAggregatorInterface::VALIDATION_STRATEGY_STOP_ON_ERROR,
            Import::FIELD_NAME_ALLOWED_ERROR_COUNT => max(0, $this->allowedErrors),
            Import::FIELD_FIELD_SEPARATOR => ',',
            Import::FIELD_FIELD_MULTIPLE_VALUE_SEPARATOR => $this->multipleValueSeparator,
            Import::FIELD_EMPTY_ATTRIBUTE_VALUE_CONSTANT => $this->emptyValueConstant,
        ];
        if ($this->imagesDirectory !== '') {
            $data[Import::FIELD_NAME_IMG_FILE_DIR] = $this->imagesDirectory;
        }

        return $data;
    }
}
