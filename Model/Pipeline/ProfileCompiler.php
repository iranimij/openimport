<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Pipeline;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Entity\EntityInfo;
use Iranimij\OpenImport\Model\Entity\EntityRegistry;
use Iranimij\OpenImport\Model\Filter\FilterException;
use Iranimij\OpenImport\Model\Filter\RowFilter;
use Iranimij\OpenImport\Model\Filter\RowFilterCompiler;
use Iranimij\OpenImport\Model\Mapping\CompiledMapping;
use Iranimij\OpenImport\Model\Mapping\MappingCompiler;
use Iranimij\OpenImport\Model\Mapping\MappingException;
use Iranimij\OpenImport\Model\Transform\ValueMap;

/**
 * Turns a profile's mapping, value maps and row filters into their compiled form for a file's columns (runs and the
 * live preview in the profile form use the same code).
 */
class ProfileCompiler
{
    /**
     * @param MappingCompiler $mappingCompiler
     * @param RowFilterCompiler $filterCompiler
     * @param EntityRegistry $entities
     */
    public function __construct(
        private readonly MappingCompiler $mappingCompiler,
        private readonly RowFilterCompiler $filterCompiler,
        private readonly EntityRegistry $entities
    ) {
    }

    /**
     * @param ProfileInterface $profile
     * @param string[]|null $columns the file's columns when they are fixed (CSV, XLSX)
     * @return array{0: CompiledMapping, 1: EntityInfo}
     * @throws PipelineException
     */
    public function mapping(ProfileInterface $profile, ?array $columns): array
    {
        if ($profile->getMapping() === []) {
            throw new PipelineException('The profile has no mapping yet.');
        }
        $fields = [];
        $maps = [];
        foreach ($profile->getMapping() as $row) {
            $field = $this->mappingCompiler->field($row);
            $fields[] = $field;
            $pairs = $profile->getValueMaps()[$field->target] ?? [];
            if ($pairs !== []) {
                $maps[$field->target] = new ValueMap(
                    $pairs,
                    !empty($row['value_map_case_sensitive']),
                    !empty($row['value_map_strict'])
                );
            }
        }
        $entity = $this->entities->resolve($profile->getEntity(), $fields);
        try {
            return [$this->mappingCompiler->compile($fields, $entity, $maps, $columns), $entity];
        } catch (MappingException $exception) {
            throw new PipelineException("The mapping does not fit this file:\n" . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param ProfileInterface $profile
     * @param string[]|null $columns
     * @return RowFilter
     * @throws PipelineException
     */
    public function filter(ProfileInterface $profile, ?array $columns): RowFilter
    {
        try {
            return $this->filterCompiler->compile($profile->getRowFilters(), $columns);
        } catch (FilterException $exception) {
            throw new PipelineException("The row filters do not fit this file:\n" . $exception->getMessage(), 0, $exception);
        }
    }
}
