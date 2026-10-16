<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Admin;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\JsonField;
use Iranimij\OpenImport\Model\Schedule\DailyTime;
use Iranimij\OpenImport\Model\Security\Secrets;

/**
 * Moves a profile into the admin form's data and the posted form back into the profile.
 *
 * Value maps are edited as text, one pair per line: "from => to".
 */
class ProfileFormMapper
{
    public const SCHEDULE_PRESETS = [
        '' => 'Manual only',
        '*/15 * * * *' => 'Every 15 minutes',
        '*/30 * * * *' => 'Every 30 minutes',
        '0 * * * *' => 'Every hour',
        '0 2 * * *' => 'Every night at 02:00',
        '0 6 * * 1-5' => 'Weekdays at 06:00',
    ];
    public const SCHEDULE_CUSTOM = 'custom';
    public const SCHEDULE_DAILY = 'daily';

    /**
     * @param JsonField $json
     * @param Secrets $secrets
     * @param DailyTime $dailyTime
     */
    public function __construct(
        private readonly JsonField $json,
        private readonly Secrets $secrets,
        private readonly DailyTime $dailyTime
    ) {
    }

    /**
     * @param ProfileInterface $profile
     * @return array<string, mixed>
     */
    public function toForm(ProfileInterface $profile): array
    {
        $schedule = $profile->getSchedule() ?? '';
        $mapping = [];
        $valueMaps = $profile->getValueMaps();
        foreach ($profile->getMapping() as $position => $row) {
            $target = (string) ($row['target'] ?? '');
            $mapping[] = [
                'record_id' => (string) $position,
                'target' => $target,
                'source' => (string) ($row['source'] ?? ''),
                'static' => (string) ($row['static'] ?? ''),
                'transforms' => $this->json->encode(is_array($row['transforms'] ?? null) ? $row['transforms'] : []),
                'required' => !empty($row['required']) ? '1' : '0',
                'value_map' => $this->mapToText($valueMaps[$target] ?? []),
                'value_map_case_sensitive' => !empty($row['value_map_case_sensitive']) ? '1' : '0',
                'value_map_strict' => !empty($row['value_map_strict']) ? '1' : '0',
            ];
        }
        $filters = $profile->getRowFilters();
        $conditions = [];
        foreach (is_array($filters['conditions'] ?? null) ? $filters['conditions'] : [] as $position => $condition) {
            $conditions[] = [
                'record_id' => (string) $position,
                'column' => (string) ($condition['column'] ?? ''),
                'operator' => (string) ($condition['operator'] ?? (isset($condition['expression']) ? 'expression' : 'equals')),
                'value' => (string) ($condition['expression'] ?? $condition['value'] ?? ''),
            ];
        }
        $source = $this->secrets->strip($profile->getSourceConfig());
        foreach ($this->secrets->stored($profile->getSourceConfig()) as $name => $stored) {
            $source[$name . '_stored'] = $stored ? '1' : '0';
        }
        $export = $profile->getExportOptions();
        // The status filter is a multiselect: a stored "pending, processing" becomes a list.
        if (is_scalar($export['order']['statuses'] ?? null)) {
            $export['order']['statuses'] = array_values(array_filter(
                array_map('trim', explode(',', (string) $export['order']['statuses'])),
                static fn (string $status): bool => $status !== ''
            ));
        }

        return [
            'profile_id' => $profile->getProfileId(),
            'name' => $profile->getName(),
            'direction' => $profile->getDirection(),
            'entity' => $profile->getEntity(),
            'is_active' => $profile->isActive() ? '1' : '0',
            'source_type' => $profile->getSourceType(),
            'source_config' => $source,
            'format' => $profile->getFormat(),
            // An unset has_header means "yes"; the toggle must show (and post) that, not an empty value.
            'format_options' => $profile->getFormatOptions() + ['has_header' => '1'],
            'export_options' => $this->secrets->strip($export)
                + array_combine(
                    array_map(static fn (string $name): string => $name . '_stored', array_keys($this->secrets->stored($profile->getExportOptions()))),
                    array_map(static fn (bool $stored): string => $stored ? '1' : '0', $this->secrets->stored($profile->getExportOptions()))
                ),
            'import_options' => $this->importOptionsForForm($profile->getImportOptions()),
            'behavior' => $profile->getBehavior(),
            'on_error' => $profile->getOnError(),
            'allowed_errors' => (string) $profile->getAllowedErrors(),
            'delta' => $profile->isDelta() ? '1' : '0',
            'safety_threshold' => $profile->getSafetyThreshold() === null ? '' : (string) $profile->getSafetyThreshold(),
            'schedule_preset' => $this->schedulePreset($schedule),
            'schedule_custom' => $this->schedulePreset($schedule) === self::SCHEDULE_CUSTOM ? $schedule : '',
            'schedule_time' => $this->dailyTime->timeOf($schedule) ?? '',
            'after_success' => $profile->getAfterSuccess(),
            'notify' => $profile->getNotify(),
            'notify_emails' => $profile->getNotifyEmails(),
            'filter_mode' => (string) ($filters['mode'] ?? 'include'),
            'filter_match' => (string) ($filters['match'] ?? 'all'),
            'mapping' => $mapping,
            'row_filters' => $conditions,
        ];
    }

    /**
     * @param array<string, mixed> $data posted form data
     * @param ProfileInterface $profile
     * @return ProfileInterface
     */
    public function fromForm(array $data, ProfileInterface $profile): ProfileInterface
    {
        $text = static fn (string $key, string $default = ''): string
            => isset($data[$key]) && is_scalar($data[$key]) ? trim((string) $data[$key]) : $default;
        $flag = static fn (string $key): bool => !empty($data[$key]);

        $profile->setName($text('name'))
            ->setDirection($text('direction', ProfileInterface::DIRECTION_IMPORT))
            ->setEntity($text('entity'))
            ->setIsActive($flag('is_active'))
            ->setSourceType($text('source_type', 'upload'))
            ->setFormat($text('format', 'auto'))
            ->setBehavior($text('behavior', ProfileInterface::BEHAVIOR_ADD_UPDATE))
            ->setOnError($text('on_error', ProfileInterface::ON_ERROR_STOP))
            ->setAllowedErrors((int) $text('allowed_errors', '0'))
            ->setDelta($flag('delta'))
            ->setSafetyThreshold($text('safety_threshold') === '' ? null : (int) $text('safety_threshold'))
            ->setAfterSuccess($text('after_success', 'keep'))
            ->setNotify($text('notify', 'failure'))
            ->setNotifyEmails($text('notify_emails'));

        $preset = $text('schedule_preset');
        $profile->setSchedule(match ($preset) {
            self::SCHEDULE_CUSTOM => $text('schedule_custom'),
            self::SCHEDULE_DAILY => $this->dailyTime->cronOf($text('schedule_time', '02:00')),
            default => $preset,
        });

        $source = is_array($data['source_config'] ?? null) ? $data['source_config'] : [];
        $source = array_map(static fn ($value) => is_scalar($value) ? trim((string) $value) : $value, $source);
        foreach (array_keys($source) as $key) {
            if (str_ends_with((string) $key, '_stored')) {
                unset($source[$key]);
            }
        }
        $profile->setSourceConfig($this->secrets->merge($source, $profile->getSourceConfig()));

        $export = is_array($data['export_options'] ?? null) ? $data['export_options'] : [];
        $export = array_map(static fn ($value) => is_scalar($value) ? trim((string) $value) : $value, $export);
        foreach (array_keys($export) as $key) {
            if (str_ends_with((string) $key, '_stored')) {
                unset($export[$key]);
            }
        }
        $profile->setExportOptions($this->secrets->merge($export, $profile->getExportOptions()));

        $importOptions = is_array($data['import_options'] ?? null) ? $data['import_options'] : [];
        $profile->setImportOptions(array_filter(
            array_map(
                // Multiselects (variation attributes) are kept as a comma list.
                static fn ($value) => is_array($value)
                    ? implode(',', array_filter(array_map('trim', array_map('strval', $value))))
                    : (is_scalar($value) ? trim((string) $value) : ''),
                $importOptions
            ),
            static fn (string $value): bool => $value !== '' && $value !== '0'
        ));

        $options = is_array($data['format_options'] ?? null) ? $data['format_options'] : [];
        $profile->setFormatOptions(array_filter(
            array_map(static fn ($value) => is_scalar($value) ? (string) $value : '', $options),
            static fn (string $value): bool => $value !== ''
        ));

        [$mapping, $maps] = $this->mappingFromForm(is_array($data['mapping'] ?? null) ? $data['mapping'] : []);
        $profile->setMapping($mapping)->setValueMaps($maps);

        $conditions = [];
        foreach (is_array($data['row_filters'] ?? null) ? $data['row_filters'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $operator = (string) ($row['operator'] ?? 'equals');
            $value = (string) ($row['value'] ?? '');
            if ($operator === 'expression') {
                if (trim($value) !== '') {
                    $conditions[] = ['expression' => $value];
                }
                continue;
            }
            if (trim((string) ($row['column'] ?? '')) === '') {
                continue;
            }
            $conditions[] = ['column' => (string) $row['column'], 'operator' => $operator, 'value' => $value];
        }
        $profile->setRowFilters($conditions === [] ? [] : [
            'mode' => $text('filter_mode', 'include'),
            'match' => $text('filter_match', 'all'),
            'conditions' => $conditions,
        ]);

        return $profile;
    }

    /**
     * Import options with the form's defaults; the variation attributes as a list for the multiselect.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function importOptionsForForm(array $options): array
    {
        $options += [
            'images_on_error' => 'error',
            'configurable' => '0',
            'configurable_child_visibility' => 'hide',
            'missing_action' => '',
            'missing_scope' => 'all',
            'qty_mode' => 'set',
            'url_redirects' => '0',
        ];
        $options['configurable_attributes'] = array_values(array_filter(
            array_map('trim', explode(',', (string) ($options['configurable_attributes'] ?? ''))),
            static fn (string $code): bool => $code !== ''
        ));

        return $options;
    }

    /**
     * @param array<int|string, mixed> $rows
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, array<string, string>>}
     */
    private function mappingFromForm(array $rows): array
    {
        $mapping = [];
        $maps = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !empty($row['delete'])) {
                continue;
            }
            $target = trim((string) ($row['target'] ?? ''));
            $source = trim((string) ($row['source'] ?? ''));
            $static = (string) ($row['static'] ?? '');
            $transforms = $this->json->decode($row['transforms'] ?? '[]');
            if ($target === '' && $source === '' && $static === '') {
                continue;
            }
            $mapping[] = [
                'target' => $target,
                'source' => $source === '' ? null : $source,
                'static' => $source === '' && $static !== '' ? $static : null,
                'transforms' => array_values($transforms),
                'required' => !empty($row['required']),
                'value_map_case_sensitive' => !empty($row['value_map_case_sensitive']),
                'value_map_strict' => !empty($row['value_map_strict']),
            ];
            $pairs = $this->textToMap((string) ($row['value_map'] ?? ''));
            if ($pairs !== [] && $target !== '') {
                $maps[$target] = $pairs;
            }
        }

        return [$mapping, $maps];
    }

    /**
     * @param array<string, string> $map
     * @return string
     */
    public function mapToText(array $map): string
    {
        $lines = [];
        foreach ($map as $from => $to) {
            $lines[] = $from . ' => ' . $to;
        }

        return implode("\n", $lines);
    }

    /**
     * "from => to" per line; lines without "=>" are ignored, the first "=>" separates.
     *
     * @param string $text
     * @return array<string, string>
     */
    public function textToMap(string $text): array
    {
        $map = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (!str_contains($line, '=>')) {
                continue;
            }
            [$from, $to] = explode('=>', $line, 2);
            $from = trim($from);
            if ($from !== '' && !array_key_exists($from, $map)) {
                $map[$from] = trim($to);
            }
        }

        return $map;
    }

    /**
     * @param string $schedule cron expression ('' = manual)
     * @return string the form's preset: a listed one, "daily" (every day at a time) or "custom"
     */
    private function schedulePreset(string $schedule): string
    {
        if (array_key_exists($schedule, self::SCHEDULE_PRESETS)) {
            return $schedule;
        }

        return $this->dailyTime->timeOf($schedule) !== null ? self::SCHEDULE_DAILY : self::SCHEDULE_CUSTOM;
    }
}
