<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Console\Command;

use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Model\Storage\ErrorLog;
use Iranimij\OpenImport\Model\Storage\PreviewStore;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints a run for the CLI: status, counts, message, first errors and (dry runs) the first changes.
 */
class RunReport
{
    private const SHOWN = 20;

    /**
     * @param ErrorLog $errors
     * @param PreviewStore $preview
     */
    public function __construct(
        private readonly ErrorLog $errors,
        private readonly PreviewStore $preview
    ) {
    }

    /**
     * @param RunInterface $run
     * @param OutputInterface $output
     * @return void
     */
    public function write(RunInterface $run, OutputInterface $output): void
    {
        $runId = (int) $run->getRunId();
        $tag = match ($run->getStatus()) {
            RunInterface::STATUS_SUCCESS => 'info',
            RunInterface::STATUS_PARTIAL, RunInterface::STATUS_NEEDS_CONFIRMATION,
            RunInterface::STATUS_QUEUED, RunInterface::STATUS_RUNNING => 'comment',
            default => 'error',
        };
        $output->writeln(sprintf(
            'Run %d · %s · %s · <%s>%s</%s>',
            $runId,
            $run->getProfileName(),
            $run->isDryRun() ? 'dry run' : 'live',
            $tag,
            str_replace('_', ' ', $run->getStatus()),
            $tag
        ));
        if ($run->getMessage() !== '') {
            $output->writeln($run->getMessage());
        }
        $counts = array_filter($run->getCounts(), static fn (int $count): bool => $count > 0);
        if ($counts !== []) {
            $table = new Table($output);
            $table->setHeaders(array_keys($counts));
            $table->addRow(array_map('strval', array_values($counts)));
            $table->render();
        }
        if ($run->isDryRun()) {
            $changes = array_slice($this->preview->fetch($runId, self::SHOWN), 0, self::SHOWN);
            $rows = [];
            foreach ($changes as $record) {
                if ($record->diff === []) {
                    continue;
                }
                foreach ($record->diff as $field => [$before, $after]) {
                    $rows[] = [$record->identifier, $record->changeType, $field, $before ?? '–', $after ?? '–'];
                }
            }
            if ($rows !== []) {
                $output->writeln('Changes (first records):');
                $table = new Table($output);
                $table->setHeaders(['Record', 'Change', 'Field', 'Now', 'After the run']);
                $table->setRows(array_slice($rows, 0, 50));
                $table->render();
            }
        }
        $errors = $this->errors->fetch($runId, self::SHOWN);
        if ($errors !== []) {
            $output->writeln(sprintf(
                'Errors (%s of %d):',
                count($errors) < $run->getCount('errors') ? 'first ' . count($errors) : 'all',
                $run->getCount('errors')
            ));
            $table = new Table($output);
            $table->setHeaders(['Row', 'Record', 'Field', 'Message']);
            foreach ($errors as $error) {
                $table->addRow([
                    $error->sourceRow > 0 ? (string) $error->sourceRow : '–',
                    $error->identifier,
                    $error->field,
                    $error->message,
                ]);
            }
            $table->render();
        }
    }
}
