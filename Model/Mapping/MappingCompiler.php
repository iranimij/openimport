<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Mapping;

use Iranimij\OpenImport\Model\Entity\EntityInfo;
use Iranimij\OpenImport\Model\Transform\TransformException;
use Iranimij\OpenImport\Model\Transform\TransformPool;
use Iranimij\OpenImport\Model\Transform\TransformScope;
use Iranimij\OpenImport\Model\Transform\ValueMap;

/**
 * Checks a profile's mapping and compiles it (transforms included) once per run.
 */
class MappingCompiler
{
    /**
     * @param TransformPool $transforms
     */
    public function __construct(
        private readonly TransformPool $transforms
    ) {
    }

    /**
     * @param FieldMapping[] $fields
     * @param EntityInfo $entity
     * @param array<string, ValueMap> $valueMaps target field => value map
     * @param string[]|null $sourceColumns the file's columns; when given, mapped source columns must exist
     * @return CompiledMapping
     * @throws MappingException listing every problem
     */
    public function compile(
        array $fields,
        EntityInfo $entity,
        array $valueMaps = [],
        ?array $sourceColumns = null
    ): CompiledMapping {
        $problems = [];
        $compiled = [];
        $seen = [];
        $defaultColumns = [];
        $storeColumns = [];
        foreach ($fields as $position => $field) {
            $label = $field->target !== '' ? $field->target : sprintf('row %d', $position + 1);
            $problem = $this->check($field, $entity, $seen, $sourceColumns);
            if ($problem !== null) {
                $problems[] = sprintf('%s: %s', $label, $problem);
                if ($field->target !== '' && $field->storeView() === null) {
                    // Mapped, just wrongly: no extra "map the identifier" hint for it.
                    $defaultColumns[] = $field->column();
                }
                continue;
            }
            $seen[strtolower($field->target)] = true;
            try {
                $chain = $this->transforms->compile(
                    $field->transforms,
                    new TransformScope($field->target, $valueMaps[$field->target] ?? null)
                );
            } catch (TransformException $exception) {
                $problems[] = sprintf('%s: %s', $label, $exception->getMessage());
                if ($field->storeView() === null) {
                    $defaultColumns[] = $field->column();
                }
                continue;
            }
            $source = $field->source;
            $static = $field->static ?? '';
            $read = $field->transforms === []
                ? static fn (array $row): string => $source !== null ? (string) ($row[$source] ?? '') : $static
                : static fn (array $row): string
                    => $chain($source !== null ? (string) ($row[$source] ?? '') : $static, $row);
            $store = $field->storeView();
            $compiled[] = [
                'field' => $field->target,
                'column' => $field->column(),
                'store' => $store,
                'read' => $read,
                'required' => $field->required,
            ];
            if ($store === null) {
                $defaultColumns[] = $field->column();
            } else {
                $storeColumns[] = $field->column();
            }
        }
        $missing = array_diff($entity->identifier, $defaultColumns);
        if ($missing !== []) {
            $problems[] = sprintf(
                count($missing) === 1
                    ? 'Map the identifier column %s (a source column or a fixed value).'
                    : 'Map the identifier columns %s (a source column or a fixed value).',
                implode(', ', $missing)
            );
        }
        if ($problems !== []) {
            throw new MappingException(implode("\n", $problems));
        }
        $columns = array_values(array_unique(array_merge(
            array_values(array_intersect($entity->identifier, $defaultColumns)),
            $defaultColumns,
            $storeColumns === [] ? [] : ['store_view_code'],
            $storeColumns
        )));
        $carry = array_values(array_unique(array_merge($entity->identifier, $entity->storeViewCarry)));

        return new CompiledMapping($compiled, $columns, $entity->identifier, $carry);
    }

    /**
     * A mapping row from stored data (openimport_mapping row or profile JSON).
     *
     * @param array<string, mixed> $data
     * @return FieldMapping
     */
    public function field(array $data): FieldMapping
    {
        $text = static fn (string $key): ?string => isset($data[$key]) && is_scalar($data[$key])
            && trim((string) $data[$key]) !== '' ? (string) $data[$key] : null;
        $transforms = $data['transforms'] ?? [];
        if (is_string($transforms)) {
            $transforms = $transforms === '' ? [] : json_decode($transforms, true);
        }

        return new FieldMapping(
            trim((string) $text('target')),
            $text('source'),
            isset($data['static']) && is_scalar($data['static']) ? (string) $data['static'] : null,
            is_array($transforms) ? array_values($transforms) : [],
            !empty($data['required'])
        );
    }

    /**
     * @param FieldMapping $field
     * @param EntityInfo $entity
     * @param array<string, true> $seen
     * @param string[]|null $sourceColumns
     * @return string|null the problem, or null
     */
    private function check(FieldMapping $field, EntityInfo $entity, array $seen, ?array $sourceColumns): ?string
    {
        if ($field->target === '') {
            return 'the Magento field is empty';
        }
        if (isset($seen[strtolower($field->target)])) {
            return 'the field is mapped twice';
        }
        if (str_starts_with($field->target, FieldMapping::STORE_PREFIX)) {
            if (!$entity->storeViews) {
                return sprintf('store-view values are not supported for %s', $entity->code);
            }
            if ($field->storeView() === null || $field->column() === '') {
                return 'write store-view fields as store:<store view code>:<field>';
            }
            if (in_array($field->column(), $entity->identifier, true) || $field->column() === 'store_view_code') {
                return 'this field cannot have a store-view value';
            }
        }
        if ($entity->columns !== [] && !in_array($field->column(), $entity->columns, true)) {
            return sprintf('%s can only set %s', $entity->code, implode(', ', $entity->columns));
        }
        if ($field->source !== null && $sourceColumns !== null && !in_array($field->source, $sourceColumns, true)) {
            return sprintf('the file has no column "%s"', $field->source);
        }

        return null;
    }
}
