<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\UrlRewrite;

use Iranimij\OpenImport\Model\Entity\RecordReaderInterface;

/**
 * What the shop's URL rewrites look like now (by request path and store view code), for the dry-run preview and
 * the safety threshold. The identifier is the request path (with "|store view code" appended when given); without
 * a store view the default store view is looked at.
 */
class UrlRewriteRecordReader implements RecordReaderInterface
{
    /**
     * @param UrlRewriteRecords $records
     * @param UrlRewriteEntitySource $source
     */
    public function __construct(
        private readonly UrlRewriteRecords $records,
        private readonly UrlRewriteEntitySource $source
    ) {
    }

    /**
     * @inheritDoc
     */
    public function existing(array $identifiers): array
    {
        $existing = [];
        foreach ($identifiers as $identifier) {
            if ($this->lookup((string) $identifier) !== null) {
                $existing[mb_strtolower((string) $identifier)] = true;
            }
        }

        return $existing;
    }

    /**
     * @inheritDoc
     */
    public function current(array $identifiers, array $columns, ?string $storeCode = null): array
    {
        $current = [];
        foreach ($identifiers as $identifier) {
            $rewrite = $this->lookup((string) $identifier);
            if ($rewrite === null) {
                continue;
            }
            $row = $this->source->row($rewrite);
            $values = [];
            foreach ($columns as $column) {
                if ($column === 'request_path' || $column === 'store_code') {
                    continue;
                }
                $values[$column] = $row[$column] ?? '';
            }
            $current[mb_strtolower((string) $identifier)] = $values;
        }

        return $current;
    }

    /**
     * @inheritDoc
     */
    public function total(): int
    {
        return $this->records->count();
    }

    /**
     * @param string $identifier request path|store view code
     * @return array<string, mixed>|null
     */
    private function lookup(string $identifier): ?array
    {
        [$path, $code] = array_pad(explode('|', $identifier, 2), 2, '');
        $code = strtolower(trim($code));
        $storeId = $code === '' ? $this->records->defaultStoreId() : ($this->records->storeCodes()[$code] ?? null);
        if ($storeId === null) {
            return null;
        }

        return $this->records->find(ltrim(trim($path), '/'), $storeId);
    }
}
