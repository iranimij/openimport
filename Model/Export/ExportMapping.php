<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Export;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Mapping\MappingCompiler;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Transform\TransformException;
use Iranimij\OpenImport\Model\Transform\TransformPool;
use Iranimij\OpenImport\Model\Transform\TransformScope;
use Iranimij\OpenImport\Model\Transform\ValueMap;

/**
 * The columns of an export file: each mapping row is one output column (target) read from an entity column
 * (source), a fixed value, or both through transforms and value maps. Without a mapping, every entity column is
 * written as it is. Rows for exports have no store-view prefixes and no identifier rules.
 */
class ExportMapping
{
    /**
     * @var array<int, array{0: string, 1: \Closure}> output column and reader
     */
    private array $columns = [];

    /**
     * @param MappingCompiler $fields
     * @param TransformPool $transforms
     */
    public function __construct(
        private readonly MappingCompiler $fields,
        private readonly TransformPool $transforms
    ) {
    }

    /**
     * @param ProfileInterface $profile
     * @return self a compiled copy
     * @throws PipelineException
     */
    public function compile(ProfileInterface $profile): self
    {
        $mapping = clone $this;
        $mapping->columns = [];
        $problems = [];
        $seen = [];
        foreach ($profile->getMapping() as $position => $row) {
            $field = $this->fields->field($row);
            $label = $field->target !== '' ? $field->target : sprintf('row %d', $position + 1);
            if ($field->target === '') {
                $problems[] = sprintf('%s: give the column a name.', $label);
                continue;
            }
            if (isset($seen[strtolower($field->target)])) {
                $problems[] = sprintf('%s: this column is in the file twice.', $label);
                continue;
            }
            $seen[strtolower($field->target)] = true;
            $pairs = $profile->getValueMaps()[$field->target] ?? [];
            $valueMap = $pairs === [] ? null : new ValueMap($pairs, !empty($row['value_map_case_sensitive']), !empty($row['value_map_strict']));
            try {
                $chain = $this->transforms->compile($field->transforms, new TransformScope($field->target, $valueMap));
            } catch (TransformException $exception) {
                $problems[] = sprintf('%s: %s', $label, $exception->getMessage());
                continue;
            }
            $source = $field->source;
            $static = $field->static ?? '';
            $mapping->columns[] = [
                $field->target,
                $field->transforms === []
                    ? static fn (array $row): string => $source !== null ? (string) ($row[$source] ?? '') : $static
                    : static fn (array $row): string => $chain($source !== null ? (string) ($row[$source] ?? '') : $static, $row),
            ];
        }
        if ($problems !== []) {
            throw new PipelineException("The mapping has problems:\n" . implode("\n", $problems));
        }

        return $mapping;
    }

    /**
     * @return bool whether every entity column is written as it is
     */
    public function passThrough(): bool
    {
        return $this->columns === [];
    }

    /**
     * @param string[] $entityColumns the entity's columns (used without a mapping)
     * @return string[] the file's columns
     */
    public function columns(array $entityColumns): array
    {
        return $this->passThrough() ? array_values($entityColumns) : array_map(static fn (array $column): string => $column[0], $this->columns);
    }

    /**
     * @param array<string, mixed> $row an entity row
     * @return array<string, string> the file row
     */
    public function map(array $row): array
    {
        if ($this->passThrough()) {
            return $row;
        }
        $out = [];
        foreach ($this->columns as [$column, $read]) {
            $out[$column] = $read($row);
        }

        return $out;
    }
}
