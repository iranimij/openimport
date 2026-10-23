<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Admin;

use Iranimij\OpenImport\Api\Data\RunInterface;
use Iranimij\OpenImport\Model\Config;
use Iranimij\OpenImport\Model\Execution\RunFiles;
use Iranimij\OpenImport\Model\Storage\ErrorLog;
use Iranimij\OpenImport\Model\Value\FormulaGuard;

/**
 * The error report of a run: one CSV line per error with the row number, record, field and message, followed by the
 * original row of the merchant's file, so the rows can be fixed and imported again.
 */
class ErrorReport
{
    private const BATCH = 1000;

    /**
     * @param ErrorLog $errors
     * @param RunFiles $runFiles
     * @param Config $config
     * @param FormulaGuard $guard
     */
    public function __construct(
        private readonly ErrorLog $errors,
        private readonly RunFiles $runFiles,
        private readonly Config $config,
        private readonly FormulaGuard $guard
    ) {
    }

    /**
     * Writes the report into the run folder and returns its absolute path.
     *
     * @param RunInterface $run
     * @param bool $withSourceData whether the source row's cells follow each error (they are file contents, so
     *                             only for users allowed to download run files)
     * @return string
     */
    public function write(RunInterface $run, bool $withSourceData = true): string
    {
        $runId = (int) $run->getRunId();
        $columns = [];
        if ($withSourceData) {
            for ($offset = 0; $batch = $this->errors->fetch($runId, self::BATCH, $offset); $offset += self::BATCH) {
                foreach ($batch as $error) {
                    foreach (array_keys($error->sourceData ?? []) as $column) {
                        $columns[$column] = true;
                    }
                }
            }
        }
        $columns = array_keys($columns);
        $path = $this->runFiles->folder($runId) . ($withSourceData ? '/errors.csv' : '/errors-without-rows.csv');
        $file = new \SplFileObject($path, 'wb');
        $file->fwrite("\xEF\xBB\xBF");
        $file->fputcsv(array_merge(['Row', 'Record', 'Field', 'Error'], $columns), ',', '"', '');
        $guard = $this->config->isFormulaGuardEnabled();
        for ($offset = 0; $batch = $this->errors->fetch($runId, self::BATCH, $offset); $offset += self::BATCH) {
            foreach ($batch as $error) {
                $line = [
                    $error->sourceRow > 0 ? (string) $error->sourceRow : '',
                    $error->identifier,
                    $error->field,
                    $error->message,
                ];
                foreach ($columns as $column) {
                    $line[] = (string) ($error->sourceData[$column] ?? '');
                }
                if ($guard) {
                    $line = array_map([$this->guard, 'protect'], $line);
                }
                $file->fputcsv($line, ',', '"', '');
            }
        }
        $file = null;

        return $path;
    }
}
