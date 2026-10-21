<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Small;

use Iranimij\OpenImport\Model\Bridge\ImporterInterface;
use Iranimij\OpenImport\Model\Bridge\ImportRequest;
use Iranimij\OpenImport\Model\Bridge\ImportResult;
use Iranimij\OpenImport\Model\Bridge\RowError;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\AbstractSource;
use Psr\Log\LoggerInterface;

/**
 * The shape every small entity shares: every row is checked first (prepare), the dry run counts, the live run
 * writes row by row (write or remove) through Magento's models. Add/Update creates or updates, Delete removes,
 * Replace is refused.
 */
abstract class SmallImporter implements ImporterInterface
{
    /**
     * @param LoggerInterface $logger
     */
    public function __construct(protected readonly LoggerInterface $logger)
    {
    }

    /**
     * What the entity is called in messages, plural ("Reviews").
     *
     * @return string
     */
    abstract protected function what(): string;

    /**
     * Checks a row. Returns ['error' => [column, message]] on a problem, else what write()/remove() need, with
     * 'key' (lower-cased, to spot duplicates in the file) and 'exists' (bool).
     *
     * @param array<string, string> $row trimmed values by column
     * @param string $behavior
     * @return array<string, mixed>
     */
    abstract protected function prepare(array $row, string $behavior): array;

    /**
     * @param array<string, mixed> $prepared
     * @return void
     */
    abstract protected function write(array $prepared): void;

    /**
     * @param array<string, mixed> $prepared
     * @return void
     */
    abstract protected function remove(array $prepared): void;

    /**
     * @inheritDoc
     */
    public function run(ImportRequest $request, AbstractSource $source): ImportResult
    {
        $started = microtime(true);
        $errors = [];
        $ready = [];
        $position = 0;
        if ($request->behavior === Import::BEHAVIOR_REPLACE) {
            $errors[] = new RowError(
                null,
                'behavior',
                $this->what() . ' cannot be imported with "Replace". Use Add/Update or Delete.',
                null,
                true
            );
        }
        $seen = [];
        $created = $updated = $deleted = 0;
        foreach ($source as $row) {
            $values = [];
            foreach ($row as $column => $value) {
                $values[(string) $column] = trim((string) $value);
            }
            $prepared = $this->prepare($values, $request->behavior);
            if (!isset($prepared['error']) && isset($seen[$prepared['key']])) {
                $prepared = ['error' => [$prepared['column'] ?? 'identifier', 'appears twice in the file']];
            }
            if (isset($prepared['error'])) {
                [$column, $message] = $prepared['error'];
                $errors[] = new RowError($position, $column, $message, $column, true);
            } else {
                $ready[$position] = $prepared;
                $seen[$prepared['key']] = true;
                if ($request->behavior === Import::BEHAVIOR_DELETE) {
                    $deleted++;
                } elseif ($prepared['exists']) {
                    $updated++;
                } else {
                    $created++;
                }
            }
            $position++;
        }
        $invalid = count(array_filter($errors, static fn (RowError $error): bool => $error->row !== null));
        $valid = $errors === [] || ($request->skipErrors && $invalid <= $request->allowedErrors
            && !array_filter($errors, static fn (RowError $error): bool => $error->row === null));
        if ($request->dryRun || !$valid) {
            return $this->result($valid, false, $position, $invalid, $created, $updated, $deleted, $errors, $started);
        }
        $created = $updated = $deleted = 0;
        foreach ($ready as $index => $prepared) {
            try {
                if ($request->behavior === Import::BEHAVIOR_DELETE) {
                    $this->remove($prepared);
                    $deleted++;
                } else {
                    $this->write($prepared);
                    $prepared['exists'] ? $updated++ : $created++;
                }
            } catch (\Throwable $exception) {
                $this->logger->error(
                    'OpenImport ' . $this->what() . ' import: ' . $exception->getMessage(),
                    ['exception' => $exception]
                );
                $errors[] = new RowError($index, 'save', $exception->getMessage(), null, !$request->skipErrors);
                $invalid++;
                if (!$request->skipErrors) {
                    return $this->result(
                        false,
                        $created + $updated + $deleted > 0,
                        $position,
                        $invalid,
                        $created,
                        $updated,
                        $deleted,
                        $errors,
                        $started
                    );
                }
            }
        }

        return $this->result(true, true, $position, $invalid, $created, $updated, $deleted, $errors, $started);
    }

    /**
     * @param string $value
     * @return bool|null
     */
    protected function flag(string $value): ?bool
    {
        return match (strtolower($value)) {
            '1', 'yes', 'true', 'ja', 'y', 'on' => true,
            '0', 'no', 'false', 'nein', 'n', 'off' => false,
            default => null,
        };
    }

    /**
     * @param bool $valid
     * @param bool $imported
     * @param int $rows
     * @param int $invalid
     * @param int $created
     * @param int $updated
     * @param int $deleted
     * @param RowError[] $errors
     * @param float $started
     * @return ImportResult
     */
    private function result(
        bool $valid,
        bool $imported,
        int $rows,
        int $invalid,
        int $created,
        int $updated,
        int $deleted,
        array $errors,
        float $started
    ): ImportResult {
        return new ImportResult(
            $valid,
            $imported,
            $rows,
            $rows,
            $invalid,
            $created,
            $updated,
            $deleted,
            $errors,
            null,
            microtime(true) - $started
        );
    }
}
