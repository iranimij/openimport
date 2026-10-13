<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Profile;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Entity\EntityRegistry;
use Iranimij\OpenImport\Model\Filter\FilterException;
use Iranimij\OpenImport\Model\Filter\RowFilterCompiler;
use Iranimij\OpenImport\Model\Mapping\MappingCompiler;
use Iranimij\OpenImport\Model\Mapping\MappingException;
use Iranimij\OpenImport\Model\Schedule\CronExpression;
use Iranimij\OpenImport\Model\Transform\ValueMap;

/**
 * Checks a profile before it is saved and lists every problem in plain words.
 */
class ProfileValidator
{
    /**
     * @param MappingCompiler $mappingCompiler
     * @param RowFilterCompiler $filterCompiler
     * @param EntityRegistry $entities
     * @param EntityCodes $entityCodes
     */
    public function __construct(
        private readonly MappingCompiler $mappingCompiler,
        private readonly RowFilterCompiler $filterCompiler,
        private readonly EntityRegistry $entities,
        private readonly EntityCodes $entityCodes
    ) {
    }

    /**
     * @param ProfileInterface $profile
     * @return string[] problems; empty when the profile can be saved
     */
    public function validate(ProfileInterface $profile): array
    {
        $problems = [];
        $name = trim($profile->getName());
        if ($name === '') {
            $problems[] = 'Give the profile a name.';
        } elseif (mb_strlen($name) > 255) {
            $problems[] = 'The name is longer than 255 characters.';
        }
        $direction = $profile->getDirection();
        $import = $direction === ProfileInterface::DIRECTION_IMPORT;
        if (!in_array($direction, [ProfileInterface::DIRECTION_IMPORT, ProfileInterface::DIRECTION_EXPORT], true)) {
            $problems[] = sprintf('The direction "%s" is not import or export.', $direction);
        }
        $entity = $profile->getEntity();
        if ($entity === '') {
            $problems[] = 'Choose what to import or export.';
        } elseif (!in_array($entity, $this->entityCodes->codes($direction), true)) {
            $problems[] = sprintf('Magento has no %s entity "%s".', $direction, $entity);
        }
        $problems = array_merge($problems, $this->choices($profile, $import));
        $threshold = $profile->getSafetyThreshold();
        if ($threshold !== null && ($threshold < 1 || $threshold > 100)) {
            $problems[] = 'The safety threshold must be between 1 and 100 percent.';
        }
        $schedule = $profile->getSchedule();
        if ($schedule !== null) {
            try {
                new CronExpression($schedule);
            } catch (\InvalidArgumentException $exception) {
                $problems[] = $exception->getMessage();
            }
        }
        foreach (array_filter(array_map('trim', explode(',', $profile->getNotifyEmails()))) as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $problems[] = sprintf('"%s" is not an e-mail address.', $email);
            }
        }
        if ($import && $entity !== '') {
            $known = $this->entities->get($entity);
            if ($known !== null && $known->existingOnly && !in_array(
                $profile->getBehavior(),
                [ProfileInterface::BEHAVIOR_ADD_UPDATE, ProfileInterface::BEHAVIOR_ONLY_UPDATE],
                true
            )) {
                $problems[] = sprintf('%s only updates existing records: use Add/Update or Only update.', $entity);
            }
            $problems = array_merge($problems, $this->mapping($profile));
        }
        if ($import && $profile->getRowFilters() !== []) {
            try {
                $this->filterCompiler->compile($profile->getRowFilters());
            } catch (FilterException $exception) {
                $problems = array_merge($problems, explode("\n", $exception->getMessage()));
            }
        }

        return $problems;
    }

    /**
     * @param ProfileInterface $profile
     * @param bool $import
     * @return string[]
     */
    private function choices(ProfileInterface $profile, bool $import): array
    {
        $checks = [
            ['source type', $profile->getSourceType(), ProfileInterface::SOURCE_TYPES],
            ['format', $profile->getFormat(), ProfileInterface::FORMATS],
            ['after-success action', $profile->getAfterSuccess(), ProfileInterface::AFTER_SUCCESS_OPTIONS],
            ['notification setting', $profile->getNotify(), ProfileInterface::NOTIFY_OPTIONS],
        ];
        if ($import) {
            $checks[] = ['behaviour', $profile->getBehavior(), ProfileInterface::BEHAVIORS];
            $checks[] = ['error handling', $profile->getOnError(),
                [ProfileInterface::ON_ERROR_STOP, ProfileInterface::ON_ERROR_SKIP]];
        }
        $problems = [];
        foreach ($checks as [$label, $value, $allowed]) {
            if (!in_array($value, $allowed, true)) {
                $problems[] = sprintf('The %s "%s" is not one of %s.', $label, $value, implode(', ', $allowed));
            }
        }

        return $problems;
    }

    /**
     * @param ProfileInterface $profile
     * @return string[]
     */
    private function mapping(ProfileInterface $profile): array
    {
        if ($profile->getMapping() === []) {
            return [];
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
        try {
            $this->mappingCompiler->compile($fields, $this->entities->resolve($profile->getEntity(), $fields), $maps);
        } catch (MappingException $exception) {
            return explode("\n", $exception->getMessage());
        }

        return [];
    }
}
