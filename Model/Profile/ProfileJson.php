<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Profile;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\ProfileInterfaceFactory;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Security\Secrets;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Profiles as JSON, for moving them between stores and keeping them in version control: every setting, mapping
 * and value map, never the secrets (passwords, keys, tokens) and never the upload token. Importing is all or
 * nothing: every profile is validated first; a profile with the same name is updated, otherwise created.
 */
class ProfileJson
{
    public const VERSION = 1;

    private const FIELDS = ['name', 'direction', 'entity', 'is_active', 'source_type', 'source_config', 'format',
        'format_options', 'behavior', 'on_error', 'allowed_errors', 'delta', 'safety_threshold', 'schedule',
        'after_success', 'notify', 'notify_emails', 'row_filters', 'export_options', 'mapping', 'value_maps'];

    /**
     * @param ProfileRepositoryInterface $profiles
     * @param ProfileInterfaceFactory $factory
     * @param ProfileValidator $validator
     * @param Secrets $secrets
     */
    public function __construct(
        private readonly ProfileRepositoryInterface $profiles,
        private readonly ProfileInterfaceFactory $factory,
        private readonly ProfileValidator $validator,
        private readonly Secrets $secrets
    ) {
    }

    /**
     * @param ProfileInterface[] $profiles
     * @return string pretty JSON
     */
    public function encode(array $profiles): string
    {
        $out = ['openimport' => self::VERSION, 'profiles' => []];
        foreach ($profiles as $profile) {
            $out['profiles'][] = $this->toArray($profile);
        }

        return (string) json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * @param ProfileInterface $profile
     * @return array<string, mixed>
     */
    public function toArray(ProfileInterface $profile): array
    {
        $source = $this->secrets->strip($profile->getSourceConfig());
        unset($source['file'], $source['file_name']);

        return [
            'name' => $profile->getName(),
            'direction' => $profile->getDirection(),
            'entity' => $profile->getEntity(),
            'is_active' => $profile->isActive(),
            'source_type' => $profile->getSourceType(),
            'source_config' => $source,
            'format' => $profile->getFormat(),
            'format_options' => $profile->getFormatOptions(),
            'behavior' => $profile->getBehavior(),
            'on_error' => $profile->getOnError(),
            'allowed_errors' => $profile->getAllowedErrors(),
            'delta' => $profile->isDelta(),
            'safety_threshold' => $profile->getSafetyThreshold(),
            'schedule' => $profile->getSchedule(),
            'after_success' => $profile->getAfterSuccess(),
            'notify' => $profile->getNotify(),
            'notify_emails' => $profile->getNotifyEmails(),
            'row_filters' => $profile->getRowFilters(),
            'export_options' => $this->secrets->strip($profile->getExportOptions()),
            'mapping' => $profile->getMapping(),
            'value_maps' => $profile->getValueMaps(),
        ];
    }

    /**
     * Checks a JSON document and returns the profiles it would save, without saving.
     *
     * @param string $json
     * @return array<int, array{0: ProfileInterface, 1: bool}> profile and whether it exists already
     * @throws \InvalidArgumentException listing every problem
     */
    public function prepare(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['profiles']) || !is_array($data['profiles'])) {
            throw new \InvalidArgumentException('The file is not an OpenImport profile export (no "profiles" list).');
        }
        if ((int) ($data['openimport'] ?? 0) > self::VERSION) {
            throw new \InvalidArgumentException(sprintf('The file is from a newer OpenImport (format %d).', (int) $data['openimport']));
        }
        $prepared = [];
        $problems = [];
        $names = [];
        foreach (array_values($data['profiles']) as $position => $row) {
            $label = is_array($row) && !empty($row['name']) ? (string) $row['name'] : sprintf('profile %d', $position + 1);
            if (!is_array($row)) {
                $problems[] = $label . ': not an object.';
                continue;
            }
            foreach (array_keys($row) as $key) {
                if (!in_array($key, self::FIELDS, true)) {
                    $problems[] = sprintf('%s: unknown setting "%s".', $label, (string) $key);
                }
            }
            if (isset($names[strtolower($label)])) {
                $problems[] = $label . ': appears twice in the file.';
            }
            $names[strtolower($label)] = true;
            $existing = null;
            try {
                $existing = $this->profiles->getByName($label);
            } catch (NoSuchEntityException $exception) {
                $existing = null;
            }
            $profile = $this->apply($existing ?? $this->factory->create(), $row);
            foreach ($this->validator->validate($profile) as $problem) {
                $problems[] = $label . ': ' . $problem;
            }
            $prepared[] = [$profile, $existing !== null];
        }
        if ($problems !== []) {
            throw new \InvalidArgumentException(implode("\n", $problems));
        }

        return $prepared;
    }

    /**
     * Saves what prepare() returned.
     *
     * @param array<int, array{0: ProfileInterface, 1: bool}> $prepared
     * @return array{0: int, 1: int} created, updated
     */
    public function save(array $prepared): array
    {
        $created = 0;
        $updated = 0;
        foreach ($prepared as [$profile, $exists]) {
            $this->profiles->save($profile);
            $exists ? $updated++ : $created++;
        }

        return [$created, $updated];
    }

    /**
     * @param ProfileInterface $profile
     * @param array<string, mixed> $row
     * @return ProfileInterface
     */
    private function apply(ProfileInterface $profile, array $row): ProfileInterface
    {
        $text = static fn (string $key, string $default): string => is_scalar($row[$key] ?? null) ? trim((string) $row[$key]) : $default;
        $list = static fn (string $key): array => is_array($row[$key] ?? null) ? $row[$key] : [];
        $secretsSource = $profile->getSourceConfig()[Secrets::KEY] ?? null;
        $secretsExport = $profile->getExportOptions()[Secrets::KEY] ?? null;
        $source = $this->secrets->strip($list('source_config'));
        $export = $this->secrets->strip($list('export_options'));
        if ($secretsSource !== null) {
            $source[Secrets::KEY] = $secretsSource;
        }
        if ($secretsExport !== null) {
            $export[Secrets::KEY] = $secretsExport;
        }

        return $profile
            ->setName($text('name', ''))
            ->setDirection($text('direction', ProfileInterface::DIRECTION_IMPORT))
            ->setEntity($text('entity', ''))
            ->setIsActive(!empty($row['is_active']))
            ->setSourceType($text('source_type', 'upload'))
            ->setSourceConfig($source)
            ->setFormat($text('format', 'auto'))
            ->setFormatOptions($list('format_options'))
            ->setBehavior($text('behavior', ProfileInterface::BEHAVIOR_ADD_UPDATE))
            ->setOnError($text('on_error', ProfileInterface::ON_ERROR_STOP))
            ->setAllowedErrors((int) ($row['allowed_errors'] ?? 0))
            ->setDelta(!empty($row['delta']))
            ->setSafetyThreshold(is_numeric($row['safety_threshold'] ?? null) ? (int) $row['safety_threshold'] : null)
            ->setSchedule($text('schedule', '') === '' ? null : $text('schedule', ''))
            ->setAfterSuccess($text('after_success', 'keep'))
            ->setNotify($text('notify', 'failure'))
            ->setNotifyEmails($text('notify_emails', ''))
            ->setRowFilters($list('row_filters'))
            ->setExportOptions($export)
            ->setMapping($list('mapping'))
            ->setValueMaps($list('value_maps'));
    }
}
