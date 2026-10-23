<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Cms;

use Iranimij\OpenImport\Model\Bridge\ImporterInterface;
use Iranimij\OpenImport\Model\Bridge\ImportRequest;
use Iranimij\OpenImport\Model\Bridge\ImportResult;
use Iranimij\OpenImport\Model\Bridge\RowError;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\AbstractSource;
use Psr\Log\LoggerInterface;

/**
 * CMS pages and blocks by identifier. One class, two kinds (di.xml: "page" and "block"); the kind decides the
 * repository and the columns. A record is matched by identifier within the store views it is assigned to
 * (`stores`: "all" or a comma list of store view codes; Magento allows the same identifier once per store view).
 *
 * Add/Update creates or updates, Delete removes, Replace is refused. Writes go through the CMS repositories.
 */
class CmsImporter implements ImporterInterface
{
    public const PAGE_COLUMNS = ['identifier', 'stores', 'title', 'content', 'content_heading', 'page_layout',
        'is_active', 'meta_title', 'meta_keywords', 'meta_description', 'layout_update_xml', 'custom_theme',
        'sort_order'];

    public const BLOCK_COLUMNS = ['identifier', 'stores', 'title', 'content', 'is_active'];

    /**
     * @param CmsRecords $records
     * @param LoggerInterface $logger
     * @param string $kind page or block
     */
    public function __construct(
        private readonly CmsRecords $records,
        private readonly LoggerInterface $logger,
        private readonly string $kind = CmsRecords::PAGE
    ) {
    }

    /**
     * @inheritDoc
     */
    public function run(ImportRequest $request, AbstractSource $source): ImportResult
    {
        $started = microtime(true);
        $errors = [];
        $rows = [];
        $position = 0;
        $what = $this->kind === CmsRecords::PAGE ? 'Pages' : 'Blocks';
        if ($request->behavior === Import::BEHAVIOR_REPLACE) {
            $errors[] = new RowError(null, 'behavior', $what . ' cannot be imported with "Replace" (it would change their ids). Use Add/Update or Delete.', null, true);
        }
        $columns = $this->kind === CmsRecords::PAGE ? self::PAGE_COLUMNS : self::BLOCK_COLUMNS;
        $stores = $this->records->storeCodes();
        $seen = [];
        foreach ($source as $row) {
            $problem = $this->check($row, $stores, $request->behavior, $seen);
            if ($problem !== null) {
                $errors[] = new RowError($position, $problem[0], $problem[1], $problem[0], true);
            } else {
                $rows[$position] = array_intersect_key($row, array_flip($columns));
                $seen[mb_strtolower(trim((string) $row['identifier']))] = true;
            }
            $position++;
        }
        $invalid = count(array_filter($errors, static fn (RowError $error): bool => $error->row !== null));
        $valid = $errors === [] || ($request->skipErrors && $invalid <= $request->allowedErrors
            && !array_filter($errors, static fn (RowError $error): bool => $error->row === null));
        if ($request->dryRun || !$valid) {
            return $this->result($valid, false, $position, $invalid, 0, 0, 0, $errors, $started);
        }
        $created = $updated = $deleted = 0;
        foreach ($rows as $index => $row) {
            try {
                if ($request->behavior === Import::BEHAVIOR_DELETE) {
                    $deleted += $this->records->delete($this->kind, (string) $row['identifier'], $this->storeIds($row, $stores)) ? 1 : 0;
                } else {
                    $outcome = $this->records->save($this->kind, $row, $this->storeIds($row, $stores));
                    $created += $outcome === CmsRecords::CREATED ? 1 : 0;
                    $updated += $outcome === CmsRecords::UPDATED ? 1 : 0;
                }
            } catch (\Throwable $exception) {
                $this->logger->error('OpenImport CMS import: ' . $exception->getMessage(), ['exception' => $exception]);
                $errors[] = new RowError($index, 'save', $exception->getMessage(), null, !$request->skipErrors);
                $invalid++;
                if (!$request->skipErrors) {
                    return $this->result(false, $created + $updated + $deleted > 0, $position, $invalid, $created, $updated, $deleted, $errors, $started);
                }
            }
        }

        return $this->result(true, true, $position, $invalid, $created, $updated, $deleted, $errors, $started);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int> $stores
     * @param string $behavior
     * @param array<string, true> $seen
     * @return array{0: string, 1: string}|null
     */
    private function check(array $row, array $stores, string $behavior, array $seen): ?array
    {
        $identifier = trim((string) ($row['identifier'] ?? ''));
        if ($identifier === '') {
            return ['identifier', 'the identifier is empty'];
        }
        if (preg_match('/^[a-z0-9][a-z0-9_\-\/]*$/i', $identifier) !== 1) {
            return ['identifier', 'use letters, digits, dashes, underscores and slashes'];
        }
        if (isset($seen[mb_strtolower($identifier)])) {
            return ['identifier', 'appears twice in the file'];
        }
        foreach ($this->storeNames($row) as $code) {
            if ($code !== 'all' && !isset($stores[$code])) {
                return ['stores', sprintf('there is no store view "%s" (use "all" or store view codes separated by commas)', $code)];
            }
        }
        if (isset($row['is_active']) && trim((string) $row['is_active']) !== '' && $this->records->flag($row['is_active']) === null) {
            return ['is_active', 'use 1 or 0 (also yes/no, true/false)'];
        }
        $existing = $this->records->find($this->kind, $identifier, $this->storeIds($row, $stores));
        if ($behavior === Import::BEHAVIOR_DELETE && $existing === null) {
            return ['identifier', $this->kind === CmsRecords::PAGE ? 'there is no such page' : 'there is no such block'];
        }
        if ($behavior !== Import::BEHAVIOR_DELETE && $existing === null && trim((string) ($row['title'] ?? '')) === '') {
            return ['title', $this->kind === CmsRecords::PAGE ? 'a new page needs a title' : 'a new block needs a title'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return string[] lower-cased store view codes, [] when the column is empty
     */
    private function storeNames(array $row): array
    {
        return array_values(array_filter(
            array_map(static fn (string $code): string => strtolower(trim($code)), explode(',', (string) ($row['stores'] ?? ''))),
            static fn (string $code): bool => $code !== ''
        ));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int> $stores
     * @return int[] store ids; [0] = all store views; [] = not given
     */
    private function storeIds(array $row, array $stores): array
    {
        $ids = [];
        foreach ($this->storeNames($row) as $code) {
            if ($code === 'all') {
                return [0];
            }
            $ids[] = $stores[$code];
        }

        return $ids;
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
        return new ImportResult($valid, $imported, $rows, $rows, $invalid, $created, $updated, $deleted, $errors, null, microtime(true) - $started);
    }
}
