<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Mapping;

use Iranimij\OpenImport\Model\Entity\EntityInfo;

/**
 * Suggests a mapping from the file's column names: exact name, then ignoring case, spaces, underscores, hyphens
 * and dots, then the entity's synonyms (Artikelnummer → sku, Bestand → qty, Preis → price …), then store-view
 * columns written as "name_de", "name (de)", "name [de]" or "store:de:name".
 */
class AutoMatcher
{
    /**
     * @param string[] $sourceColumns
     * @param string[] $targets core columns that may be mapped
     * @param EntityInfo $entity
     * @param string[] $storeCodes store view codes (for store-view columns)
     * @return array<string, string> target => source column
     */
    public function match(array $sourceColumns, array $targets, EntityInfo $entity, array $storeCodes = []): array
    {
        $matches = [];
        $used = [];
        $assign = static function (string $target, string $source) use (&$matches, &$used): void {
            if (!isset($matches[$target]) && !isset($used[$source])) {
                $matches[$target] = $source;
                $used[$source] = true;
            }
        };
        foreach ($sourceColumns as $source) {
            if (in_array($source, $targets, true)) {
                $assign($source, $source);
            }
        }
        $byKey = [];
        foreach ($targets as $target) {
            $byKey[$this->key($target)] ??= $target;
        }
        foreach ($sourceColumns as $source) {
            $target = $byKey[$this->key($source)] ?? null;
            if ($target !== null) {
                $assign($target, $source);
            }
        }
        $synonyms = [];
        foreach ($entity->synonyms as $synonym => $target) {
            $synonyms[$this->key((string) $synonym)] = $target;
        }
        foreach ($sourceColumns as $source) {
            $target = $synonyms[$this->key($source)] ?? null;
            if ($target !== null && in_array($target, $targets, true)) {
                $assign($target, $source);
            }
        }
        if ($entity->storeViews && $storeCodes !== []) {
            foreach ($sourceColumns as $source) {
                [$base, $store] = $this->splitStore($source, $storeCodes) ?? [null, null];
                if ($base === null || isset($used[$source])) {
                    continue;
                }
                $target = $byKey[$this->key($base)] ?? ($synonyms[$this->key($base)] ?? null);
                if ($target !== null && in_array($target, $targets, true)
                    && !in_array($target, $entity->identifier, true)) {
                    $assign(FieldMapping::STORE_PREFIX . $store . ':' . $target, $source);
                }
            }
        }

        return $matches;
    }

    /**
     * "name_de", "name-de", "name de", "name (de)", "name [de]", "store:de:name" => ["name", "de"]; longest store
     * code first.
     *
     * @param string $source
     * @param string[] $storeCodes
     * @return array{0: string, 1: string}|null
     */
    private function splitStore(string $source, array $storeCodes): ?array
    {
        $name = mb_strtolower(trim($source));
        // OpenImport's own notation: "store:de:name".
        if (str_starts_with($name, FieldMapping::STORE_PREFIX)) {
            $parts = explode(':', trim($source), 3);
            $code = strtolower($parts[1] ?? '');
            $base = trim($parts[2] ?? '');
            foreach ($storeCodes as $storeCode) {
                if (strtolower($storeCode) === $code && $base !== '') {
                    return [$base, $code];
                }
            }

            return null;
        }
        usort($storeCodes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($storeCodes as $code) {
            $code = strtolower($code);
            foreach (['_' . $code, '-' . $code, ' ' . $code, '(' . $code . ')', '[' . $code . ']'] as $suffix) {
                if (str_ends_with($name, $suffix)) {
                    $base = trim(substr(trim($source), 0, -strlen($suffix)));
                    if ($base !== '') {
                        return [$base, $code];
                    }
                }
            }
        }

        return null;
    }

    private function key(string $name): string
    {
        return (string) preg_replace('/[\s_\-.]+/u', '', mb_strtolower(trim($name)));
    }
}
