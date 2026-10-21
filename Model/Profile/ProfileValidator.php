<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Profile;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Configurable\ConfigurableStep;
use Iranimij\OpenImport\Model\Connection\ConnectionConfig;
use Iranimij\OpenImport\Model\Entity\EntityRegistry;
use Iranimij\OpenImport\Model\Execution\Source\FileSelector;
use Iranimij\OpenImport\Model\Export\DestinationPool;
use Iranimij\OpenImport\Model\Filter\FilterException;
use Iranimij\OpenImport\Model\Filter\RowFilterCompiler;
use Iranimij\OpenImport\Model\Mapping\MappingCompiler;
use Iranimij\OpenImport\Model\Missing\MissingProductsStep;
use Iranimij\OpenImport\Model\Missing\MissingScope;
use Iranimij\OpenImport\Model\Mapping\MappingException;
use Iranimij\OpenImport\Model\Schedule\CronExpression;
use Iranimij\OpenImport\Model\Stock\StockChangeStep;
use Iranimij\OpenImport\Model\UrlKey\UrlKeyPattern;
use Iranimij\OpenImport\Model\UrlKey\UrlKeyStep;
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
     * @param ConnectionConfig $connections
     */
    public function __construct(
        private readonly MappingCompiler $mappingCompiler,
        private readonly RowFilterCompiler $filterCompiler,
        private readonly EntityRegistry $entities,
        private readonly EntityCodes $entityCodes,
        private readonly DestinationPool $destinations,
        private readonly WriterFactory $writers,
        private readonly ConnectionConfig $connections
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
        if ($schedule !== null && $profile->isActive()) {
            // A schedule needs a file that changes by itself and a place for the result.
            if ($import && $profile->getSourceType() === 'upload') {
                $problems[] = 'An uploaded file cannot run on a schedule '
                    . '(it would import the same file again and again): choose a server folder, web address, '
                    . 'SFTP or FTP as the source, or set the schedule to "Manual only".';
            }
            if (!$import && ($profile->getExportOptions()['destination'] ?? 'download') === 'download') {
                $problems[] = 'A scheduled export needs a destination: choose a server folder, SFTP, FTP or e-mail, '
                    . 'or set the schedule to "Manual only".';
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
            if (in_array($entity, EntityRegistry::NO_REPLACE, true)
                && $profile->getBehavior() === ProfileInterface::BEHAVIOR_REPLACE
            ) {
                $problems[] = sprintf('%s cannot be imported with "Replace": use Add/Update.', $entity);
            }
            $problems = array_merge(
                $problems,
                $this->mapping($profile),
                $this->configurable($profile),
                $this->missing($profile),
                $this->quantities($profile),
                $this->urlKeyPattern($profile)
            );
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
            case 's3':
            case 'google_drive':
                $problem = $this->connections->selected($config) > 0
                    ? $this->connections->problem($config, $profile->getSourceType())
                    : 'Choose a saved connection for this source.';
                if ($problem !== null) {
                    $problems[] = $problem;
                }
                break;
            case 'sftp':
            case 'ftp':
                if ($this->connections->selected($config) > 0) {
                    $problem = $this->connections->problem($config, $profile->getSourceType());
                    if ($problem !== null) {
                        $problems[] = $problem;
                    }
                    break;
                }
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
            if (in_array($destination, ['s3', 'google_drive'], true)) {
                $problem = $this->connections->selected($export) > 0
                    ? $this->connections->problem($export, $destination)
                    : 'Choose a saved connection for the destination.';
                if ($problem !== null) {
                    $problems[] = $problem;
                }
            } elseif (in_array($destination, ['sftp', 'ftp'], true) && $this->connections->selected($export) > 0) {
                $problem = $this->connections->problem($export, $destination);
                if ($problem !== null) {
                    $problems[] = $problem;
                }
            } elseif (in_array($destination, ['sftp', 'ftp'], true)) {
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
     * "Build configurable products" needs the variation attributes and their columns, and the parent SKU column.
     *
     * @param ProfileInterface $profile
     * @return string[]
     */
    private function configurable(ProfileInterface $profile): array
    {
        $options = $profile->getImportOptions();
        if ($profile->getEntity() !== 'catalog_product' || empty($options[ConfigurableStep::OPTION_ENABLED])) {
            return [];
        }
        $targets = array_map(static fn (array $row): string => (string) ($row['target'] ?? ''), $profile->getMapping());
        $problems = [];
        if (!in_array(ConfigurableStep::COLUMN_PARENT_SKU, $targets, true)) {
            $problems[] = 'Map a column to "Parent SKU (configurable products)" in step 4 to build configurable'
                . ' products.';
        }
        $attributes = array_filter(array_map(
            'trim',
            explode(',', (string) ($options[ConfigurableStep::OPTION_ATTRIBUTES] ?? ''))
        ));
        if ($attributes === []) {
            $problems[] = 'Choose the variation attributes to build configurable products.';
        }
        foreach ($attributes as $attribute) {
            if (!in_array($attribute, $targets, true)) {
                $problems[] = sprintf('Map the variation attribute "%s" in step 4.', $attribute);
            }
        }

        return $problems;
    }

    /**
     * "Products missing from the file" needs a scope it can find, and a behaviour that keeps records.
     *
     * @param ProfileInterface $profile
     * @return string[]
     */
    private function missing(ProfileInterface $profile): array
    {
        $options = $profile->getImportOptions();
        $action = (string) ($options[MissingProductsStep::OPTION_ACTION] ?? '');
        if ($profile->getEntity() !== 'catalog_product' || $action === '') {
            return [];
        }
        $actions = [MissingProductsStep::ACTION_DISABLE, MissingProductsStep::ACTION_OUT_OF_STOCK,
            MissingProductsStep::ACTION_DELETE];
        if (!in_array($action, $actions, true)) {
            return [sprintf('Products missing from the file: "%s" is not one of %s.', $action, implode(', ', $actions))];
        }
        $problems = [];
        if (!in_array($profile->getBehavior(), [ProfileInterface::BEHAVIOR_ADD_UPDATE,
            ProfileInterface::BEHAVIOR_ONLY_UPDATE], true)) {
            $problems[] = 'Products missing from the file work with Add/Update and Only update.';
        }
        $scope = (string) ($options[MissingProductsStep::OPTION_SCOPE] ?? MissingScope::ALL) ?: MissingScope::ALL;
        if ($scope === MissingScope::WEBSITE && trim((string) ($options[MissingProductsStep::OPTION_WEBSITE] ?? '')) === '') {
            $problems[] = 'Products missing from the file: choose the website.';
        } elseif ($scope === MissingScope::ATTRIBUTE && (
            trim((string) ($options[MissingProductsStep::OPTION_ATTRIBUTE] ?? '')) === ''
            || trim((string) ($options[MissingProductsStep::OPTION_VALUE] ?? '')) === ''
        )) {
            $problems[] = 'Products missing from the file: enter the attribute code and the value.';
        } elseif (!in_array($scope, [MissingScope::ALL, MissingScope::WEBSITE, MissingScope::ATTRIBUTE], true)) {
            $problems[] = sprintf('Products missing from the file: unknown scope "%s".', $scope);
        }

        return $problems;
    }

    /**
     * A URL key pattern needs fields in braces that the mapping sets.
     *
     * @param ProfileInterface $profile
     * @return string[]
     */
    private function urlKeyPattern(ProfileInterface $profile): array
    {
        $pattern = trim((string) ($profile->getImportOptions()[UrlKeyStep::OPTION_PATTERN] ?? ''));
        if ($pattern === '' || $profile->getEntity() !== 'catalog_product') {
            return [];
        }
        $fields = (new UrlKeyPattern())->fields($pattern);
        if ($fields === []) {
            return ['Write the fields of the URL key pattern in braces, for example {name}-{sku}.'];
        }
        $targets = array_map(static fn (array $row): string => (string) ($row['target'] ?? ''), $profile->getMapping());
        $missing = array_diff($fields, $targets);

        return $missing === [] || $targets === []
            ? []
            : [sprintf('The URL key pattern uses %s; map it in step 4.', implode(', ', $missing))];
    }

    /**
     * Quantities as changes cannot skip unchanged rows (the same +5 is a new delivery).
     *
     * @param ProfileInterface $profile
     * @return string[]
     */
    private function quantities(ProfileInterface $profile): array
    {
        $mode = (string) ($profile->getImportOptions()[StockChangeStep::OPTION_MODE] ?? '');
        if ($mode !== StockChangeStep::MODE_ADD) {
            return [];
        }

        return $profile->isDelta()
            ? ['"Skip unchanged rows" cannot be used with quantities as changes: switch one of them off.']
            : [];
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
