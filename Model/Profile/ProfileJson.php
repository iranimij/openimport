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
use Iranimij\OpenImport\Model\Connection\ConnectionConfig;
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

    /**
     * Key of a saved connection's name in source_config and export_options of a file.
     */
    public const CONNECTION = 'connection';

    private const FIELDS = ['name', 'direction', 'entity', 'is_active', 'source_type', 'source_config', 'format',
        'format_options', 'behavior', 'on_error', 'allowed_errors', 'delta', 'safety_threshold', 'schedule',
        'after_success', 'notify', 'notify_emails', 'row_filters', 'export_options', 'import_options', 'mapping',
        'value_maps', 'then_profile', 'then_when', 'triggers'];

    /**
     * @var array<int, string> prepared profile index => the name of the profile to run next (resolved on save)
     */
    private array $chains = [];

    /**
     * @param ProfileRepositoryInterface $profiles
     * @param ProfileInterfaceFactory $factory
     * @param ProfileValidator $validator
     * @param Secrets $secrets
     * @param ConnectionConfig $connections
     */
    public function __construct(
        private readonly ProfileRepositoryInterface $profiles,
        private readonly ProfileInterfaceFactory $factory,
        private readonly ProfileValidator $validator,
        private readonly Secrets $secrets,
        private readonly ConnectionConfig $connections
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
        $source = $this->connectionByName($this->secrets->strip($profile->getSourceConfig()));
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
            'export_options' => $this->connectionByName($this->secrets->strip($profile->getExportOptions())),
            'import_options' => $profile->getImportOptions(),
            'then_profile' => $this->thenProfileName($profile),
            'then_when' => $profile->getThenWhen(),
            'triggers' => $profile->getTriggers(),
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
        if ($data === null && trim($json) !== 'null') {
            throw new \InvalidArgumentException(sprintf(
                'The file is not valid JSON (%s). Use a file from "Download as JSON" or openimport:profiles:export.',
                trim($json) === '' ? 'it is empty' : json_last_error_msg()
            ));
        }
        if (!is_array($data) || !isset($data['profiles']) || !is_array($data['profiles'])) {
            throw new \InvalidArgumentException('The file is not an OpenImport profile export (no "profiles" list).');
        }
        if ((int) ($data['openimport'] ?? 0) > self::VERSION) {
            throw new \InvalidArgumentException(sprintf('The file is from a newer OpenImport (format %d).', (int) $data['openimport']));
        }
        $prepared = [];
        $problems = [];
        $names = [];
        $this->chains = [];
        $fileNames = [];
        foreach ($data['profiles'] as $row) {
            if (is_array($row) && !empty($row['name'])) {
                $fileNames[strtolower((string) $row['name'])] = true;
            }
        }
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
            foreach (['source_config', 'export_options'] as $key) {
                if (is_array($row[$key] ?? null)) {
                    [$row[$key], $problem] = $this->connectionById($row[$key]);
                    if ($problem !== null) {
                        $problems[] = $label . ': ' . $problem;
                    }
                }
            }
            $existing = null;
            try {
                $existing = $this->profiles->getByName($label);
            } catch (NoSuchEntityException $exception) {
                $existing = null;
            }
            $wasActive = $existing !== null && $existing->isActive();
            $profile = $this->apply($existing ?? $this->factory->create(), $row);
            $next = is_scalar($row['then_profile'] ?? null) ? trim((string) $row['then_profile']) : '';
            if ($next !== '') {
                try {
                    $profile->setThenProfileId((int) $this->profiles->getByName($next)->getProfileId());
                } catch (NoSuchEntityException $exception) {
                    if (!isset($fileNames[strtolower($next)])) {
                        $problems[] = sprintf('%s: there is no profile named "%s" to run next.', $label, $next);
                    }
                    // A profile of this file: linked once every profile is saved.
                    $this->chains[count($prepared)] = $next;
                }
            }
            // Automatic runs are never switched on by a file: new profiles start off (run a dry run first), updated
            // ones keep their state.
            $profile->setIsActive($wasActive);
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
        foreach ($this->chains as $index => $next) {
            [$profile] = $prepared[$index];
            $profile->setThenProfileId((int) $this->profiles->getByName($next)->getProfileId());
            $this->profiles->save($profile);
        }
        $this->chains = [];

        return [$created, $updated];
    }

    /**
     * @param ProfileInterface $profile
     * @return string the name of the profile that runs next, "" for none
     */
    private function thenProfileName(ProfileInterface $profile): string
    {
        if ($profile->getThenProfileId() === null) {
            return '';
        }
        try {
            return $this->profiles->getById($profile->getThenProfileId())->getName();
        } catch (NoSuchEntityException $exception) {
            return '';
        }
    }

    /**
     * Saved connections travel by name: their ids differ between shops.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function connectionByName(array $config): array
    {
        $id = $this->connections->selected($config);
        unset($config[ConnectionConfig::ID]);
        $name = $id > 0 ? $this->connections->nameById($id) : null;
        if ($name !== null) {
            $config[self::CONNECTION] = $name;
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config from a file
     * @return array{0: array<string, mixed>, 1: string|null} the settings with the connection's id, and a problem
     */
    private function connectionById(array $config): array
    {
        $name = is_scalar($config[self::CONNECTION] ?? null) ? trim((string) $config[self::CONNECTION]) : '';
        unset($config[self::CONNECTION], $config[ConnectionConfig::ID]);
        if ($name === '') {
            return [$config, null];
        }
        $id = $this->connections->idByName($name);
        if ($id === null) {
            return [$config, sprintf('there is no saved connection "%s". Create it under OpenImport Connections first.', $name)];
        }
        $config[ConnectionConfig::ID] = (string) $id;

        return [$config, null];
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
            ->setThenProfileId(null)
            ->setThenWhen($text('then_when', ProfileInterface::THEN_SUCCESS))
            ->setTriggers($list('triggers'))
            ->setRowFilters($list('row_filters'))
            ->setExportOptions($export)
            ->setImportOptions($list('import_options'))
            ->setMapping($list('mapping'))
            ->setValueMaps($list('value_maps'));
    }
}
