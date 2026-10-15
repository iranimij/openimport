<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Bridge;

use Magento\ImportExport\Model\HistoryFactory;
use Magento\ImportExport\Model\Import\AbstractSource;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingError;
use Magento\ImportExport\Model\ResourceModel\Import\Data as ImportData;

/**
 * Hands a prepared source to Magento's own import engine: the same validate + import steps the admin import page
 * runs, so the data written is exactly what core would write for an equivalent CSV file.
 *
 * - Dry run: validation only. The bunches validation saved are removed again, so nothing stays behind that a later
 *   import could pick up.
 * - Live run: a row in core's import history (so the run shows up under System › Data Transfer › Import History and
 *   core's history updates land on it instead of on someone else's import), import, invalidate the affected
 *   indexers like the admin import does, then remove this run's bunches.
 *
 * Core's error aggregator is a shared instance (one import per admin request in core). OpenImport runs several
 * imports in one process (cron, CLI), so it is cleared before every run; otherwise errors of earlier runs count
 * against this run's allowed errors (M0 spike finding).
 */
class CoreImporter implements ImporterInterface
{
    /**
     * Validations at most per run when rows with critical errors are left out (the first one included).
     */
    private const MAX_PASSES = 4;

    /**
     * @param CoreImportFactory $importFactory
     * @param HistoryFactory $historyFactory
     * @param ImportData $importData
     * @param CoreMessages $messages
     */
    public function __construct(
        private readonly CoreImportFactory $importFactory,
        private readonly HistoryFactory $historyFactory,
        private readonly ImportData $importData,
        private readonly CoreMessages $messages = new CoreMessages()
    ) {
    }

    /**
     * Validates the source and, unless this is a dry run or validation failed, imports it.
     *
     * "Skip rows with errors": core's entities stop saving every row of an import as soon as one row has a critical
     * error, even with the skip-errors strategy (Product::_saveProducts and others ask hasToBeTerminated() per row),
     * and still report the import as done. So the rows core rejected are left out and the rest is validated again,
     * until no critical error is left (at most MAX_PASSES validations). Errors of every pass point at the rows of
     * the original source.
     *
     * @param ImportRequest $request
     * @param AbstractSource $source
     * @return ImportResult
     */
    public function run(ImportRequest $request, AbstractSource $source): ImportResult
    {
        $started = microtime(true);
        $bunchIds = [];
        try {
            [$import, $valid] = $this->validate($request, $source, $bunchIds);
            $rows = (int) $import->getProcessedRowsCount();
            $entities = (int) $import->getProcessedEntitiesCount();
            if ($request->dryRun || !$valid || !$import->isImportAllowed()) {
                return $this->result($import, $valid, false, $rows, $entities, null, $started);
            }

            $earlier = [];
            $skipped = [];
            $current = $source;
            for ($pass = 1; $request->skipErrors && $import->getErrorAggregator()->hasFatalExceptions(); $pass++) {
                $before = $earlier;
                $added = 0;
                $rowless = false;
                foreach ($this->errors($import, $current) as $error) {
                    $earlier[] = $error;
                    $rowless = $rowless || $error->row === null;
                    if ($error->row !== null && !isset($skipped[$error->row])) {
                        $skipped[$error->row] = true;
                        $added++;
                    }
                }
                if ($rowless || $added === 0 || $pass >= self::MAX_PASSES) {
                    return $this->result($import, false, false, $rows, $entities, null, $started, $before, $current);
                }
                $this->removeBunches($bunchIds);
                $bunchIds = [];
                $current = new SkipRowsSource($source, array_keys($skipped));
                [$import, $valid] = $this->validate($request, $current, $bunchIds);
                if (!$valid || !$import->isImportAllowed()) {
                    return $this->result($import, false, false, $rows, $entities, null, $started, $earlier, $current);
                }
            }

            $historyId = $this->openHistory($request->historyLabel);
            $import->setData('_import_history_id', $historyId);
            $imported = (bool) $import->importSource();
            $import->invalidateIndex();

            return $this->result($import, true, $imported, $rows, $entities, $historyId, $started, $earlier, $current);
        } finally {
            $this->removeBunches($bunchIds);
        }
    }

    /**
     * One validation pass with a fresh import model. Core's error aggregator is shared, so it is cleared first.
     *
     * @param ImportRequest $request
     * @param AbstractSource $source
     * @param int[] $bunchIds collects the bunches validation saved, for removal
     * @return array{0: CoreImport, 1: bool} the import model and whether validation passed
     */
    private function validate(ImportRequest $request, AbstractSource $source, array &$bunchIds): array
    {
        /** @var CoreImport $import */
        $import = $this->importFactory->create();
        $import->setData($request->toImportData());
        $import->getErrorAggregator()->clear();
        try {
            $valid = (bool) $import->validateSource($source);
        } finally {
            $bunchIds = array_merge($bunchIds, $import->getBunchIds());
        }

        return [$import, $valid];
    }

    /**
     * The import's errors, with row positions in the original source.
     *
     * @param CoreImport $import
     * @param AbstractSource|null $source
     * @return RowError[]
     */
    private function errors(CoreImport $import, ?AbstractSource $source): array
    {
        $errors = [];
        foreach ($import->getErrorAggregator()->getAllErrors() as $error) {
            $row = $this->rowError($error);
            if ($row->row !== null && $source instanceof SkipRowsSource) {
                $row = new RowError(
                    $source->originalPosition($row->row),
                    $row->code,
                    $row->message,
                    $row->column,
                    $row->critical
                );
            }
            $errors[] = $row;
        }

        return $this->messages->explain($errors);
    }

    /**
     * Builds the result from the import's counters and error aggregator.
     *
     * @param CoreImport $import
     * @param bool $valid
     * @param bool $imported
     * @param int $rows
     * @param int $entities
     * @param int|null $historyId
     * @param float $started
     * @param RowError[] $earlier errors of earlier validation passes (their rows were left out)
     * @param AbstractSource|null $source the source of the last pass, to map rows back
     * @return ImportResult
     */
    private function result(
        CoreImport $import,
        bool $valid,
        bool $imported,
        int $rows,
        int $entities,
        ?int $historyId,
        float $started,
        array $earlier = [],
        ?AbstractSource $source = null
    ): ImportResult {
        $errors = array_merge($earlier, $this->errors($import, $source));
        $skipped = [];
        foreach ($earlier as $error) {
            if ($error->row !== null) {
                $skipped[$error->row] = true;
            }
        }

        return new ImportResult(
            $valid,
            $imported,
            $rows,
            $entities,
            count($skipped) + (int) $import->getErrorAggregator()->getInvalidRowsCount(),
            $imported ? (int) $import->getCreatedItemsCount() : 0,
            $imported ? (int) $import->getUpdatedItemsCount() : 0,
            $imported ? (int) $import->getDeletedItemsCount() : 0,
            $errors,
            $historyId,
            microtime(true) - $started
        );
    }

    /**
     * Converts one of core's processing errors.
     *
     * @param ProcessingError $error
     * @return RowError
     */
    private function rowError(ProcessingError $error): RowError
    {
        $row = $error->getRowNumber();
        $column = $error->getColumnName();
        $message = (string) $error->getErrorMessage();

        return new RowError(
            $row === null || $row === '' ? null : (int) $row,
            (string) $error->getErrorCode(),
            $message !== '' ? $message : (string) $error->getErrorCode(),
            $column === null || $column === '' ? null : (string) $column,
            $error->getErrorLevel() === ProcessingError::ERROR_LEVEL_CRITICAL
        );
    }

    /**
     * Adds the core import history row this run's history updates go to.
     *
     * @param string $label
     * @return int
     */
    private function openHistory(string $label): int
    {
        $history = $this->historyFactory->create();
        $history->addReport($label);

        return (int) $history->getId();
    }

    /**
     * Removes this import's validated bunches from importexport_importdata.
     *
     * @param int[] $ids
     * @return void
     */
    private function removeBunches(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->importData->getConnection()->delete($this->importData->getMainTable(), ['id IN (?)' => $ids]);
    }
}
