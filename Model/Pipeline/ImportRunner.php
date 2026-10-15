<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Pipeline;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Model\Bridge\ImporterPool;
use Iranimij\OpenImport\Model\Bridge\ImportRequest;
use Iranimij\OpenImport\Model\Config;
use Iranimij\OpenImport\Model\Entity\EntityInfo;
use Iranimij\OpenImport\Model\Entity\RecordReaderInterface;
use Iranimij\OpenImport\Model\Entity\RecordReaderPool;
use Iranimij\OpenImport\Model\Filter\FilterException;
use Iranimij\OpenImport\Model\Filter\RowFilter;
use Iranimij\OpenImport\Model\Mapping\CompiledMapping;
use Iranimij\OpenImport\Model\Mapping\MappedRow;
use Iranimij\OpenImport\Model\Reader\ReaderException;
use Iranimij\OpenImport\Model\Reader\ReaderFactory;
use Iranimij\OpenImport\Model\ResourceModel\Run as RunResource;
use Iranimij\OpenImport\Model\Source\MappedSource;
use Iranimij\OpenImport\Model\Storage\ErrorLog;
use Iranimij\OpenImport\Model\Storage\ErrorRecord;
use Iranimij\OpenImport\Model\Storage\PreviewRecord;
use Iranimij\OpenImport\Model\Storage\PreviewStore;
use Iranimij\OpenImport\Model\Storage\RowHashStore;
use Magento\ImportExport\Model\Import;

/**
 * Runs one import: read → filter → map → behaviour → delta → (preview) → core validate/import → errors, hashes.
 *
 * Memory stays flat: rows are handled in batches and the prepared core rows go to a spool file in the run folder.
 * The caller (RunProcessor) stages the file, holds the locks and saves the run row.
 */
class ImportRunner
{
    /**
     * Start of the dry-run message part that says a live run will need the typed confirmation.
     */
    public const CONFIRMATION_NOTE = 'A live run will ask for confirmation';

    private const BATCH = 500;

    /**
     * Records skipped by the behaviour that the run message names (the count covers all of them).
     */
    private const SKIPPED_NAMED = 10;

    private const PROGRESS_EVERY = 2000;
    private const PREVIEW_SAMPLE = 200;
    /**
     * Required core attributes an existing-only entity may carry; empty cells are filled from the record.
     */
    private const REQUIRED_COLUMNS = ['price'];

    private const DELTA_BEHAVIORS = [ProfileInterface::BEHAVIOR_ADD_UPDATE, ProfileInterface::BEHAVIOR_ONLY_ADD,
        ProfileInterface::BEHAVIOR_ONLY_UPDATE];
    private const DESTRUCTIVE_BEHAVIORS = [ProfileInterface::BEHAVIOR_DELETE, ProfileInterface::BEHAVIOR_REPLACE];

    /**
     * Per-run state (the runner is a shared service; run() resets it).
     *
     * @var array<string, int>
     */
    private array $counts = [];

    private int $errorsStored = 0;

    private int $errorCap = 0;

    private int $runId = 0;

    /**
     * @var ErrorRecord[]
     */
    private array $pendingErrors = [];

    /**
     * @var array<string, string> delta key => hash, saved after a successful live run
     */
    private array $pendingHashes = [];

    /**
     * @var array<string, int> lines seen per identifier and store view in this run
     */
    private array $lineCounts = [];

    /**
     * @var array<string, int> preview totals by change type
     */
    private array $previewTotals = [];

    /**
     * @var PreviewRecord[]
     */
    private array $previewSample = [];

    /**
     * @var array<string, int> records kept in the sample, by change type
     */
    private array $sampleCounts = [];

    private int $existingAffected = 0;

    /**
     * @var string[] the first records skipped by the behaviour ("A-1 (row 3)"), named in the run message
     */
    private array $skippedNames = [];

    private ?\Closure $heartbeat = null;

    private ?ErrorRecord $firstError = null;

    /**
     * The behaviour this run applies (own entities that only change existing records turn Add/Update into Only
     * update).
     */
    private string $behavior = '';

    private bool $existingOnly = false;


    /**
     * @param ReaderFactory $readerFactory
     * @param ProfileCompiler $profileCompiler
     * @param RecordReaderPool $recordReaders
     * @param ImporterPool $importers
     * @param ErrorLog $errorLog
     * @param PreviewStore $previewStore
     * @param RowHashStore $rowHashes
     * @param RunResource $runResource
     * @param Config $config
     * @param PreviewBuilder $previewBuilder
     */
    public function __construct(
        private readonly ReaderFactory $readerFactory,
        private readonly ProfileCompiler $profileCompiler,
        private readonly RecordReaderPool $recordReaders,
        private readonly ImporterPool $importers,
        private readonly ErrorLog $errorLog,
        private readonly PreviewStore $previewStore,
        private readonly RowHashStore $rowHashes,
        private readonly RunResource $runResource,
        private readonly Config $config,
        private readonly PreviewBuilder $previewBuilder
    ) {
    }

    /**
     * @param ProfileInterface $profile
     * @param RunInterface $run saved run (needs its id); its mode and options are used
     * @param string $file absolute path of the staged source file
     * @param string $workDir absolute path of the run folder (spool goes there)
     * @param string $originalName the file's original name (format detection)
     * @param \Closure|null $heartbeat called with every progress update (keeps locks alive)
     * @return RunOutcome
     * @throws PipelineException when the profile or the file cannot be used at all
     */
    public function run(
        ProfileInterface $profile,
        RunInterface $run,
        string $file,
        string $workDir,
        string $originalName = '',
        ?\Closure $heartbeat = null
    ): RunOutcome {
        $this->reset((int) $run->getRunId());
        $this->heartbeat = $heartbeat;
        $options = $run->getOptions();
        $dryRun = $run->isDryRun();

        try {
            $format = $profile->getFormat() === 'auto'
                ? $this->readerFactory->detect($file, $originalName === '' ? null : $originalName)
                : $profile->getFormat();
            $reader = $this->readerFactory->create(
                $file,
                $format,
                $this->readerFactory->options($profile->getFormatOptions())
            );
            // CSV and XLSX have a fixed header; JSON and XML records may add fields after the sample.
            $strictColumns = in_array($format, ['csv', 'xlsx'], true) ? $reader->columns() : null;
        } catch (ReaderException $exception) {
            throw new PipelineException($exception->getMessage(), 0, $exception);
        }
        [$mapping, $entity] = $this->profileCompiler->mapping($profile, $strictColumns);
        $behavior = $this->behavior($profile, $entity);
        $useDelta = $profile->isDelta() && empty($options['full']) && in_array($behavior, self::DELTA_BEHAVIORS, true);
        $filter = $this->profileCompiler->filter($profile, $strictColumns);
        $recordReader = $this->recordReaders->get($profile->getEntity());
        if ($recordReader === null && !in_array($behavior, [ProfileInterface::BEHAVIOR_ADD_UPDATE,
                ProfileInterface::BEHAVIOR_REPLACE, ProfileInterface::BEHAVIOR_DELETE], true)) {
            throw new PipelineException(sprintf(
                '"%s" is not available for %s. Use Add/Update.',
                $behavior === ProfileInterface::BEHAVIOR_ONLY_ADD ? 'Only add new' : 'Only update existing',
                $profile->getEntity()
            ));
        }

        $spool = new Spool(rtrim($workDir, '/') . '/mapped.jsonl', $mapping->columns());
        $batch = [];
        try {
            foreach ($reader->rows() as $line => $row) {
                $this->counts['read']++;
                $mapped = $this->prepare($filter, $mapping, (int) $line, $row);
                if ($mapped !== null) {
                    $batch[] = [(int) $line, $mapped, $row];
                }
                if (count($batch) >= self::BATCH) {
                    $this->flush($batch, $spool, $profile, $recordReader, $mapping, $useDelta, $dryRun);
                    $batch = [];
                }
                if ($this->counts['read'] % self::PROGRESS_EVERY === 0) {
                    $this->progress();
                }
            }
            $this->flush($batch, $spool, $profile, $recordReader, $mapping, $useDelta, $dryRun);
        } catch (ReaderException $exception) {
            $spool->close();
            $this->storeErrors(true);
            throw new PipelineException($exception->getMessage(), 0, $exception);
        }
        $spool->close();

        return $this->finish($profile, $run, $spool, $entity, $recordReader, $dryRun);
    }

    private function behavior(ProfileInterface $profile, EntityInfo $entity): string
    {
        $behavior = $profile->getBehavior();
        $this->existingOnly = $entity->existingOnly;
        if (!$entity->existingOnly) {
            return $this->behavior = $behavior;
        }
        if (!in_array($behavior, [ProfileInterface::BEHAVIOR_ADD_UPDATE, ProfileInterface::BEHAVIOR_ONLY_UPDATE], true)) {
            throw new PipelineException(sprintf('%s only updates existing records. Use Add/Update.', $entity->code));
        }

        return $this->behavior = ProfileInterface::BEHAVIOR_ONLY_UPDATE;
    }

    private function reset(int $runId): void
    {
        $this->runId = $runId;
        $this->counts = array_fill_keys(RunInterface::COUNTS, 0);
        $this->errorsStored = 0;
        $this->errorCap = max(1, $this->config->getMaxErrorsPerRun());
        $this->pendingErrors = [];
        $this->pendingHashes = [];
        $this->lineCounts = [];
        $this->previewTotals = array_fill_keys(
            [PreviewRecord::NEW, PreviewRecord::CHANGED, PreviewRecord::UNCHANGED, PreviewRecord::DELETED],
            0
        );
        $this->previewSample = [];
        $this->sampleCounts = [];
        $this->existingAffected = 0;
        $this->skippedNames = [];
        $this->firstError = null;
    }

    /**
     * Filter and map one source row; errors are recorded here.
     *
     * @param RowFilter $filter
     * @param CompiledMapping $mapping
     * @param int $line
     * @param array<string, string> $row
     * @return MappedRow|null
     */
    private function prepare(RowFilter $filter, CompiledMapping $mapping, int $line, array $row): ?MappedRow
    {
        try {
            if (!$filter->keep($row)) {
                $this->counts['filtered']++;
                return null;
            }
        } catch (FilterException $exception) {
            $this->error(new ErrorRecord($line, $exception->getMessage(), '', '', $row));
            return null;
        }
        $mapped = $mapping->map($row);
        if (!$mapped->isValid()) {
            foreach ($mapped->errors as $error) {
                $this->error(new ErrorRecord($line, $error->message, $mapped->identifier, $error->field, $row));
            }
            return null;
        }

        return $mapped;
    }

    /**
     * Behaviour pre-filter, delta and preview for a batch, then into the spool.
     *
     * @param array<int, array{0: int, 1: MappedRow, 2: array<string, string>}> $batch
     * @param Spool $spool
     * @param ProfileInterface $profile
     * @param RecordReaderInterface|null $reader
     * @param CompiledMapping $mapping
     * @param bool $useDelta
     * @param bool $dryRun
     * @return void
     */
    private function flush(
        array $batch,
        Spool $spool,
        ProfileInterface $profile,
        ?RecordReaderInterface $reader,
        CompiledMapping $mapping,
        bool $useDelta,
        bool $dryRun
    ): void {
        if ($batch === []) {
            return;
        }
        $behavior = $this->behavior;
        $identifiers = array_map(static fn (array $item): string => $item[1]->identifier, $batch);
        $needsExisting = $reader !== null && (
            in_array($behavior, [ProfileInterface::BEHAVIOR_ONLY_ADD, ProfileInterface::BEHAVIOR_ONLY_UPDATE], true)
            || in_array($behavior, self::DESTRUCTIVE_BEHAVIORS, true)
        );
        $existing = $needsExisting ? $reader->existing($identifiers) : [];
        $deltaKeys = [];
        if ($useDelta) {
            foreach ($batch as $index => [, $mapped]) {
                $deltaKeys[$index] = $this->deltaKey($mapped);
            }
        }
        $stored = $useDelta ? $this->rowHashes->fetch((int) $profile->getProfileId(), $deltaKeys) : [];

        $accepted = [];
        foreach ($batch as $index => [$line, $mapped]) {
            $key = mb_strtolower($mapped->identifier);
            $exists = isset($existing[$key]);
            if (($behavior === ProfileInterface::BEHAVIOR_ONLY_ADD && $exists)
                || ($behavior === ProfileInterface::BEHAVIOR_ONLY_UPDATE && !$exists)) {
                $this->counts['skipped']++;
                if (count($this->skippedNames) < self::SKIPPED_NAMED) {
                    $this->skippedNames[] = sprintf('%s (%s)', $mapped->identifier, __('row %1', $line));
                }
                continue;
            }
            if ($useDelta) {
                $hash = sha1((string) json_encode($mapped->rows, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                if (($stored[$deltaKeys[$index]] ?? null) === $hash) {
                    $this->counts['unchanged']++;
                    continue;
                }
                $this->pendingHashes[$deltaKeys[$index]] = $hash;
            }
            if (in_array($behavior, self::DESTRUCTIVE_BEHAVIORS, true) && $exists) {
                $this->existingAffected++;
            }
            $accepted[] = [$line, $mapped, $exists];
        }
        $fill = $this->existingOnly && $reader !== null ? $this->currentRequired($accepted, $reader, $mapping) : [];
        foreach ($accepted as [$line, $mapped]) {
            foreach ($mapped->rows as $index => $row) {
                if ($index === 0 && isset($fill[mb_strtolower($mapped->identifier)])) {
                    $row = array_merge($row, $fill[mb_strtolower($mapped->identifier)]);
                }
                $spool->add($row, $line, $mapped->identifier);
            }
        }
        if ($dryRun && $accepted !== []) {
            $this->preview($accepted, $behavior, $reader, $mapping);
        }
    }

    /**
     * Existing-only entities (Stock & prices): an empty cell means "leave unchanged", but Magento refuses an empty
     * required attribute (price) when its column is in the file. Such cells get the record's current value.
     *
     * @param array<int, array{0: int, 1: MappedRow, 2: bool}> $accepted
     * @param RecordReaderInterface $reader
     * @param CompiledMapping $mapping
     * @return array<string, array<string, string>> lower-cased identifier => column => current value
     */
    private function currentRequired(array $accepted, RecordReaderInterface $reader, CompiledMapping $mapping): array
    {
        $columns = array_values(array_intersect(self::REQUIRED_COLUMNS, $mapping->columns()));
        if ($columns === []) {
            return [];
        }
        $identifiers = [];
        foreach ($accepted as [, $mapped]) {
            foreach ($columns as $column) {
                if (($mapped->main()[$column] ?? '') === '') {
                    $identifiers[] = $mapped->identifier;
                    break;
                }
            }
        }
        if ($identifiers === []) {
            return [];
        }
        $fill = [];
        foreach ($reader->current($identifiers, $columns) as $key => $current) {
            foreach ($accepted as [, $mapped]) {
                if (mb_strtolower($mapped->identifier) !== (string) $key) {
                    continue;
                }
                foreach ($columns as $column) {
                    if (($mapped->main()[$column] ?? '') === '' && ($current[$column] ?? '') !== '') {
                        $fill[(string) $key][$column] = (string) $current[$column];
                    }
                }
            }
        }

        return $fill;
    }

    /**
     * The key a source line's delta hash is stored under. A file can hold several lines per record: store-view lines
     * (same SKU, other store view), several addresses or tier prices per customer or SKU. Each gets its own key
     * (identifier, store view, n-th line of that pair in the file); the first default line keeps the bare identifier.
     *
     * @param MappedRow $mapped
     * @return string
     */
    private function deltaKey(MappedRow $mapped): string
    {
        $store = (string) ($mapped->main()['store_view_code'] ?? '');
        $pair = $mapped->identifier . RowHashStore::SEPARATOR . $store;
        $line = $this->lineCounts[$pair] = ($this->lineCounts[$pair] ?? -1) + 1;
        if ($store === '' && $line === 0) {
            return $mapped->identifier;
        }

        return $pair . RowHashStore::SEPARATOR . $line;
    }

    /**
     * @param array<int, array{0: int, 1: MappedRow, 2: bool}> $accepted
     * @param string $behavior
     * @param RecordReaderInterface|null $reader
     * @param CompiledMapping $mapping
     * @return void
     */
    private function preview(array $accepted, string $behavior, ?RecordReaderInterface $reader, CompiledMapping $mapping): void
    {
        if ($reader === null) {
            return;
        }
        $records = [];
        if ($behavior === ProfileInterface::BEHAVIOR_DELETE) {
            foreach ($accepted as [$line, $mapped, $exists]) {
                $records[] = [$this->previewBuilder->deletion($mapped->identifier, $exists, $line), true];
            }
        } else {
            $identifiers = array_map(static fn (array $item): string => $item[1]->identifier, $accepted);
            $skip = array_merge($mapping->identifierColumns(), ['store_view_code']);
            $current = $reader->current($identifiers, $mapping->columns());
            $byStore = [];
            foreach ($accepted as [$line, $mapped]) {
                $key = mb_strtolower($mapped->identifier);
                $records[] = [
                    $this->previewBuilder->compare($mapped->identifier, $mapped->main(), $current[$key] ?? null, $skip, $line),
                    true,
                ];
                foreach (array_slice($mapped->rows, 1) as $row) {
                    $byStore[$row['store_view_code']][] = [$line, $mapped, $row];
                }
            }
            foreach ($byStore as $store => $rows) {
                $storeIds = array_map(static fn (array $item): string => $item[1]->identifier, $rows);
                $storeCurrent = $reader->current($storeIds, $mapping->columns(), (string) $store);
                foreach ($rows as [$line, $mapped, $row]) {
                    $key = mb_strtolower($mapped->identifier);
                    $records[] = [
                        $this->previewBuilder->compare(
                            $mapped->identifier . ' [' . $store . ']',
                            $row,
                            isset($current[$key]) ? ($storeCurrent[$key] ?? []) : null,
                            $skip,
                            $line
                        ),
                        false,
                    ];
                }
            }
        }
        // Totals count records (default rows); store-view rows only appear in the sample.
        foreach ($records as [$record, $isMain]) {
            if ($isMain) {
                $this->previewTotals[$record->changeType] = ($this->previewTotals[$record->changeType] ?? 0) + 1;
            }
            $limit = $record->changeType === PreviewRecord::UNCHANGED
                ? (int) (self::PREVIEW_SAMPLE / 4)
                : self::PREVIEW_SAMPLE;
            if (($this->sampleCounts[$record->changeType] ?? 0) < $limit) {
                $this->previewSample[] = $record;
                $this->sampleCounts[$record->changeType] = ($this->sampleCounts[$record->changeType] ?? 0) + 1;
            }
        }
    }

    private function finish(
        ProfileInterface $profile,
        RunInterface $run,
        Spool $spool,
        EntityInfo $entity,
        ?RecordReaderInterface $reader,
        bool $dryRun
    ): RunOutcome {
        $behavior = $this->behavior;
        $ownErrors = $this->counts['errors'];
        $allowed = $profile->getAllowedErrors();
        $skipErrors = $profile->getOnError() === ProfileInterface::ON_ERROR_SKIP;
        if ($dryRun) {
            $this->previewStore->add($this->runId, $this->previewSample);
        }
        if ($ownErrors > $allowed && (!$skipErrors || $spool->count() === 0)) {
            $this->storeErrors(true);
            return $this->outcome(
                RunInterface::STATUS_FAILED,
                sprintf(
                    '%d row(s) could not be prepared (allowed: %d). Nothing was imported. See the errors below.',
                    $ownErrors,
                    $allowed
                )
            );
        }
        if ($spool->count() === 0) {
            $this->storeErrors(true);
            return $this->outcome(
                $ownErrors > 0 ? RunInterface::STATUS_PARTIAL : RunInterface::STATUS_SUCCESS,
                $this->nothingToDo()
            );
        }
        $safety = $this->safetyMessage($profile, $reader, $spool);
        if ($safety !== null && !$dryRun && empty($run->getOptions()['confirmed'])) {
            $this->storeErrors(true);
            return $this->outcome(RunInterface::STATUS_NEEDS_CONFIRMATION, $safety);
        }

        $request = new ImportRequest(
            $entity->importEntity(),
            match ($behavior) {
                ProfileInterface::BEHAVIOR_DELETE => Import::BEHAVIOR_DELETE,
                ProfileInterface::BEHAVIOR_REPLACE => Import::BEHAVIOR_REPLACE,
                default => Import::BEHAVIOR_APPEND,
            },
            $dryRun,
            $skipErrors,
            max(0, $allowed - $ownErrors),
            historyLabel: sprintf('openimport-run-%d.csv', $this->runId)
        );
        $result = $this->importers->get($entity->importEntity())->run(
            $request,
            new MappedSource($spool->columns(), static fn (): \Generator => $spool->rows())
        );
        $failedIdentifiers = [];
        foreach ($result->errors as $error) {
            $position = $error->row;
            $identifier = $position === null ? '' : $spool->identifier($position);
            if ($identifier !== '') {
                $failedIdentifiers[$identifier] = true;
            }
            $this->error(new ErrorRecord(
                $position === null ? 0 : $spool->sourceRow($position),
                $error->message,
                $identifier,
                (string) $error->column
            ));
        }
        $this->counts['invalid'] = $result->invalidRows;
        $this->storeErrors(true);

        if ($dryRun) {
            $this->counts['created'] = $this->previewTotals[PreviewRecord::NEW];
            $this->counts['updated'] = $this->previewTotals[PreviewRecord::CHANGED];
            $this->counts['deleted'] = $this->previewTotals[PreviewRecord::DELETED];
            $this->counts['unchanged'] += $this->previewTotals[PreviewRecord::UNCHANGED];
            $status = $result->valid
                ? ($this->counts['errors'] > 0 ? RunInterface::STATUS_PARTIAL : RunInterface::STATUS_SUCCESS)
                : RunInterface::STATUS_FAILED;
            return $this->outcome(
                $status,
                $this->dryRunMessage($result->valid, $safety, $reader !== null) . $this->firstProblem($result->valid)
            );
        }
        if (!$result->imported) {
            return $this->outcome(
                RunInterface::STATUS_FAILED,
                ($result->valid ? 'Magento did not import the rows.' : 'Magento refused the rows. Nothing was imported.')
                . $this->firstProblem($result->valid),
                $result->historyId
            );
        }
        $this->counts['created'] = $result->created;
        $this->counts['updated'] = $result->updated;
        $this->counts['deleted'] = $result->deleted;
        $profileId = (int) $profile->getProfileId();
        if ($behavior === ProfileInterface::BEHAVIOR_DELETE) {
            $this->rowHashes->delete($profileId, $spool->allIdentifiers());
        } elseif ($this->pendingHashes !== []) {
            $this->rowHashes->save(
                $profileId,
                array_filter(
                    $this->pendingHashes,
                    static fn (string $key): bool => !isset($failedIdentifiers[explode(RowHashStore::SEPARATOR, $key)[0]]),
                    ARRAY_FILTER_USE_KEY
                ),
                $this->runId
            );
        }

        return $this->outcome(
            $this->counts['errors'] > 0 ? RunInterface::STATUS_PARTIAL : RunInterface::STATUS_SUCCESS,
            sprintf(
                'Created %d, updated %d, deleted %d. %d row(s) unchanged, %d skipped by filters, %d with errors.',
                $this->counts['created'],
                $this->counts['updated'],
                $this->counts['deleted'],
                $this->counts['unchanged'],
                $this->counts['filtered'],
                $this->counts['errors']
            ),
            $result->historyId
        );
    }

    /**
     * Destructive runs above the safety threshold need a typed confirmation.
     */
    private function safetyMessage(ProfileInterface $profile, ?RecordReaderInterface $reader, Spool $spool): ?string
    {
        $behavior = $this->behavior;
        if (!in_array($behavior, self::DESTRUCTIVE_BEHAVIORS, true)) {
            return null;
        }
        $threshold = $profile->getSafetyThreshold() ?? $this->config->getSafetyThresholdPercent();
        $verb = $behavior === ProfileInterface::BEHAVIOR_DELETE ? 'delete' : 'replace';
        if ($reader === null) {
            return sprintf(
                'This run would %s up to %d record(s) and OpenImport cannot count them in advance. Confirm to run it.',
                $verb,
                count($spool->allIdentifiers())
            );
        }
        $total = $reader->total();
        if ($total === 0 || $this->existingAffected * 100 <= $threshold * $total) {
            return null;
        }

        return sprintf(
            'This run would %s %d of %d records (%d%%, limit %d%%). Type %s to confirm.',
            $verb,
            $this->existingAffected,
            $total,
            (int) round($this->existingAffected * 100 / $total),
            $threshold,
            strtoupper($verb)
        );
    }

    private function dryRunMessage(bool $valid, ?string $safety, bool $previewed): string
    {
        $parts = [];
        if ($previewed) {
            $parts[] = sprintf(
                'Dry run: %d new, %d changed, %d unchanged, %d deleted.',
                $this->previewTotals[PreviewRecord::NEW],
                $this->previewTotals[PreviewRecord::CHANGED],
                $this->counts['unchanged'],
                $this->previewTotals[PreviewRecord::DELETED]
            );
        } else {
            $parts[] = sprintf('Dry run: %d row(s) checked by Magento.', $this->counts['read']);
        }
        $parts[] = $valid ? 'Magento accepts the rows.' : 'Magento refuses the rows; see the errors.';
        if ($safety !== null) {
            $parts[] = self::CONFIRMATION_NOTE . ': ' . $safety;
        }

        return implode(' ', $parts);
    }

    /**
     * " First problem (row 3, price): …" for a refused run, so the run list and the CLI say why.
     */
    private function firstProblem(bool $valid): string
    {
        $first = $this->firstError;
        if ($valid || $first === null) {
            return '';
        }

        return sprintf(
            ' First problem (%s): %s',
            trim(($first->sourceRow > 0 ? 'row ' . $first->sourceRow : 'file') . ', ' . $first->field, ', '),
            $first->message
        );
    }

    private function nothingToDo(): string
    {
        return sprintf(
            'Nothing to import: %d row(s) read, %d unchanged, %d skipped by filters, %d skipped by behaviour, %d with errors.',
            $this->counts['read'],
            $this->counts['unchanged'],
            $this->counts['filtered'],
            $this->counts['skipped'],
            $this->counts['errors']
        );
    }

    private function outcome(string $status, string $message, ?int $historyId = null): RunOutcome
    {
        $this->progress();
        if ($this->skippedNames !== []) {
            $message .= "\n" . (string) __(
                $this->behavior === ProfileInterface::BEHAVIOR_ONLY_ADD
                    ? 'Skipped, already in the shop: %1'
                    : 'Skipped, not in the shop: %1',
                implode(', ', $this->skippedNames)
                . ($this->counts['skipped'] > count($this->skippedNames)
                    ? ' ' . __('and %1 more', $this->counts['skipped'] - count($this->skippedNames)) : '')
            );
        }

        return new RunOutcome($status, $this->counts, $message, $historyId);
    }

    private function error(ErrorRecord $error): void
    {
        $this->counts['errors']++;
        $this->firstError ??= $error;
        if ($this->errorsStored + count($this->pendingErrors) < $this->errorCap) {
            $this->pendingErrors[] = $error;
        }
        if (count($this->pendingErrors) >= self::BATCH) {
            $this->storeErrors(false);
        }
    }

    private function storeErrors(bool $all): void
    {
        if ($this->pendingErrors === [] || (!$all && count($this->pendingErrors) < self::BATCH)) {
            return;
        }
        $this->errorLog->add($this->runId, $this->pendingErrors);
        $this->errorsStored += count($this->pendingErrors);
        $this->pendingErrors = [];
    }

    private function progress(): void
    {
        if ($this->runId > 0) {
            $this->runResource->updateProgress($this->runId, $this->counts['read'], $this->counts);
        }
        if ($this->heartbeat !== null) {
            ($this->heartbeat)();
        }
    }
}
