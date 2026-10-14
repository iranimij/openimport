<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Export;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Model\Config;
use Iranimij\OpenImport\Model\Filter\RowFilter;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Pipeline\ProfileCompiler;
use Iranimij\OpenImport\Model\Pipeline\RunOutcome;
use Iranimij\OpenImport\Model\ResourceModel\Run as RunResource;
use Iranimij\OpenImport\Model\Writer\WriterException;
use Iranimij\OpenImport\Model\Writer\WriterFactory;
use Iranimij\OpenImport\Model\Writer\WriterInterface;

/**
 * Runs one export: entity rows → row filters → mapping → writer, streamed into the run folder. A dry run writes
 * the same file (so it can be checked) but delivers it nowhere; the live run hands it to the destination.
 */
class ExportRunner
{
    private const PROGRESS_EVERY = 500;

    /**
     * @var array<string, int>
     */
    private array $counts = [];

    private int $runId = 0;

    private ?\Closure $heartbeat = null;

    /**
     * @param EntitySourcePool $sources
     * @param ExportMapping $mapping
     * @param ProfileCompiler $compiler
     * @param WriterFactory $writers
     * @param RunResource $runResource
     * @param Config $config
     * @param FileNamer $namer
     * @param DestinationPool $destinations
     */
    public function __construct(
        private readonly EntitySourcePool $sources,
        private readonly ExportMapping $mapping,
        private readonly ProfileCompiler $compiler,
        private readonly WriterFactory $writers,
        private readonly RunResource $runResource,
        private readonly Config $config,
        private readonly FileNamer $namer,
        private readonly DestinationPool $destinations
    ) {
    }

    /**
     * @param ProfileInterface $profile
     * @param RunInterface $run
     * @param string $workDir the run folder
     * @param \Closure|null $heartbeat called with every progress update (keeps locks alive)
     * @return array{0: RunOutcome, 1: string} outcome and the absolute path of the written file ('' when none)
     * @throws PipelineException
     */
    public function run(ProfileInterface $profile, RunInterface $run, string $workDir, ?\Closure $heartbeat = null): array
    {
        $this->runId = (int) $run->getRunId();
        $this->counts = array_fill_keys(RunInterface::COUNTS, 0);
        $this->heartbeat = $heartbeat;
        $mapping = $this->mapping->compile($profile);
        $filter = $this->compiler->filter($profile, null);
        try {
            $writer = $this->writers->create($profile->getFormat());
        } catch (WriterException $exception) {
            throw new PipelineException($exception->getMessage(), 0, $exception);
        }
        $path = rtrim($workDir, '/') . '/' . $this->namer->name($profile, $run, $writer->extension());
        $options = $profile->getFormatOptions() + ['formula_guard' => $this->config->isFormulaGuardEnabled() ? '1' : '0'];
        $source = $this->sources->get($profile->getEntity());
        $opened = false;
        try {
            $source->read(
                $profile,
                function (array $columns) use ($writer, $mapping, $path, $options, &$opened): void {
                    $writer->open($path, $mapping->columns($columns), $options);
                    $opened = true;
                },
                function (array $row) use ($writer, $mapping, $filter, &$opened, $path, $options): void {
                    if (!$opened) {
                        $writer->open($path, $mapping->columns(array_keys($row)), $options);
                        $opened = true;
                    }
                    $this->counts['read']++;
                    if (!$filter->keep($row)) {
                        $this->counts['filtered']++;
                    } else {
                        $writer->write($mapping->map($row));
                        $this->counts['exported']++;
                    }
                    if ($this->counts['read'] % self::PROGRESS_EVERY === 0) {
                        $this->progress();
                    }
                }
            );
        } catch (\RuntimeException $exception) {
            // Writers throw WriterException (a RuntimeException) from inside the callbacks.
            throw new PipelineException($exception->getMessage(), 0, $exception);
        } finally {
            $writer->close();
        }
        $this->progress();
        if (!$opened || $this->counts['exported'] === 0) {
            return [new RunOutcome(
                RunInterface::STATUS_SUCCESS,
                $this->counts,
                $this->counts['read'] === 0
                    ? 'Nothing to export: Magento has no records of this kind that match the filters.'
                    : sprintf('Nothing to export: %d record(s) read, all %d skipped by the row filters.', $this->counts['read'], $this->counts['filtered'])
            ), ''];
        }
        $summary = sprintf(
            '%d record(s) read, %d skipped by filters, %d exported to %s.',
            $this->counts['read'],
            $this->counts['filtered'],
            $this->counts['exported'],
            (new \SplFileInfo($path))->getFilename()
        );
        if ($run->isDryRun()) {
            return [new RunOutcome(RunInterface::STATUS_SUCCESS, $this->counts, 'Dry run: ' . $summary . ' The file was not delivered.'), $path];
        }
        $delivered = $this->destinations->get($profile)->deliver($profile, $run, $path, $writer);

        return [new RunOutcome(RunInterface::STATUS_SUCCESS, $this->counts, $summary . ' ' . $delivered), $path];
    }

    /**
     * @return void
     */
    private function progress(): void
    {
        $this->runResource->updateProgress($this->runId, $this->counts['read'], $this->counts);
        if ($this->heartbeat !== null) {
            ($this->heartbeat)();
        }
    }
}
