<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Test\Unit\Model\Pipeline\Fake;

use Iranimij\OpenImport\Model\Bridge\CoreImporter;
use Iranimij\OpenImport\Model\Bridge\ImportRequest;
use Iranimij\OpenImport\Model\Bridge\ImportResult;
use Iranimij\OpenImport\Model\Bridge\RowError;
use Magento\ImportExport\Model\Import\AbstractSource;

/**
 * Records what reaches core and answers with a prepared result.
 */
class FakeCoreImporter extends CoreImporter
{
    public ?ImportRequest $request = null;

    /**
     * @var array<int, array<string, string>>
     */
    public array $rows = [];

    /**
     * @var string[]
     */
    public array $columns = [];

    /**
     * @var RowError[]
     */
    public array $errors = [];

    public bool $valid = true;

    /**
     * The last request; fails the test when core was never called.
     *
     * @return ImportRequest
     */
    public function lastRequest(): ImportRequest
    {
        if ($this->request === null) {
            throw new \LogicException('Core was not called.');
        }

        return $this->request;
    }

    public int $calls = 0;

    // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
    public function __construct()
    {
    }

    /**
     * @inheritDoc
     */
    public function run(ImportRequest $request, AbstractSource $source): ImportResult
    {
        $this->calls++;
        $this->request = $request;
        $this->columns = $source->getColNames();
        $this->rows = [];
        foreach ($source as $row) {
            $this->rows[] = $row;
        }
        // Core reads the source twice (validation, then import).
        $second = 0;
        foreach ($source as $row) {
            $second++;
        }
        if ($second !== count($this->rows)) {
            throw new \LogicException('The source must be readable twice.');
        }
        $invalid = count(array_unique(array_filter(array_map(static fn (RowError $e): ?int => $e->row, $this->errors), 'is_int')));
        $imported = !$request->dryRun && $this->valid;
        $entities = count(array_unique(array_column($this->rows, 'sku')));

        return new ImportResult(
            $this->valid,
            $imported,
            count($this->rows),
            $entities,
            $invalid,
            $imported ? $entities - $invalid : 0,
            0,
            $imported && $request->behavior === 'delete' ? $entities : 0,
            $this->errors,
            $imported ? 77 : null,
            0.1
        );
    }
}
