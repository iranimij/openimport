<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Profile;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Entity\EntityRegistry;
use Iranimij\OpenImport\Model\Execution\Source\FileSelector;
use Iranimij\OpenImport\Model\Export\DestinationPool;
use Iranimij\OpenImport\Model\Filter\FilterException;
use Iranimij\OpenImport\Model\Filter\RowFilterCompiler;
use Iranimij\OpenImport\Model\Mapping\MappingCompiler;
use Iranimij\OpenImport\Model\Mapping\MappingException;
use Iranimij\OpenImport\Model\Schedule\CronExpression;
use Iranimij\OpenImport\Model\Transform\ValueMap;
use Iranimij\OpenImport\Model\Writer\WriterFactory;

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
     * @param DestinationPool $destinations
     * @param WriterFactory $writers
     */
    public function __construct(
        private readonly MappingCompiler $mappingCompiler,
        private readonly RowFilterCompiler $filterCompiler,
        private readonly EntityRegistry $entities,
        private readonly EntityCodes $entityCodes,
        private readonly DestinationPool $destinations,
        private readonly WriterFactory $writers
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
        $problems = array_merge($problems, $this->choices($profile, $import), $this->source($profile));
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
     * What each source type needs; connections themselves are checked by "Test connection", not at save.
     *
     * @param ProfileInterface $profile
     * @return string[]
     */
    private function source(ProfileInterface $profile): array
    {
        $config = $profile->getSourceConfig();
        $text = static fn (string $key): string => is_scalar($config[$key] ?? null) ? trim((string) $config[$key]) : '';
        $problems = [];
        switch ($profile->getSourceType()) {
            case 'folder':
                if ($text('path') === '') {
                    $problems[] = 'Enter the server folder to read from.';
                } elseif (str_contains('/' . $text('path') . '/', '/../')) {
                    $problems[] = 'The folder may not contain "..".';
                }
                break;
            case 'url':
                if ($text('url') === '') {
                    $problems[] = 'Enter the web address of the file.';
                } elseif (preg_match('#^https?://#i', $text('url')) !== 1) {
                    $problems[] = 'The web address must start with https:// or http://.';
                }
                if ($text('auth') === 'basic' && $text('username') === '') {
                    $problems[] = 'Enter the user name for the login.';
                }
                break;
            case 'sftp':
            case 'ftp':
                if ($text('host') === '') {
                    $problems[] = 'Enter the server (host name).';
                }
                if ($text('username') === '') {
                    $problems[] = 'Enter the user name for the server.';
                }
                $port = $text('port');
                if ($port !== '' && (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535)) {
                    $problems[] = 'The port must be a number between 1 and 65535.';
                }
                break;
        }
        if ($profile->getDirection() === ProfileInterface::DIRECTION_EXPORT) {
            $export = $profile->getExportOptions();
            $out = static fn (string $key): string => is_scalar($export[$key] ?? null) ? trim((string) $export[$key]) : '';
            $destination = $out('destination');
            if ($destination !== '' && !in_array($destination, $this->destinations->types(), true)) {
                $problems[] = sprintf('"%s" is not an export destination.', $destination);
            }
            if ($destination === 'folder' && $out('path') === '') {
                $problems[] = 'Enter the server folder to write to.';
            }
            if (in_array($destination, ['sftp', 'ftp'], true)) {
                if ($out('host') === '') {
                    $problems[] = 'Enter the server (host name) of the destination.';
                }
                if ($out('username') === '') {
                    $problems[] = 'Enter the user name for the destination server.';
                }
            }
            if ($destination === 'email') {
                $emails = array_filter(array_map('trim', explode(',', $out('emails'))));
                if ($emails === []) {
                    $problems[] = 'Enter at least one e-mail address to send the file to.';
                }
                foreach ($emails as $email) {
                    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                        $problems[] = sprintf('"%s" is not an e-mail address.', $email);
                    }
                }
            }
            if (!in_array($profile->getFormat(), $this->writers->formats(), true) && $profile->getFormat() !== 'auto') {
                $problems[] = sprintf('OpenImport cannot write %s files.', $profile->getFormat());
            }
        }
        $pick = $text('pick');
        if ($pick !== '' && !in_array($pick, FileSelector::PICKS, true)) {
            $problems[] = sprintf('"%s" is not a way to choose the file.', $pick);
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
