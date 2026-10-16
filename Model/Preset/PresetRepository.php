<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Preset;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\ProfileRepositoryInterface;
use Iranimij\OpenImport\Model\Profile\ProfileJson;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\Dir;

/**
 * Ready-made profiles for files from other shop systems (presets/*.json in the module, in the profile JSON format
 * with a "preset" block: code, label, description, notes). Written from the systems' public column lists.
 */
class PresetRepository
{
    /**
     * @var array<string, array{label: string, description: string, notes: string, json: array<string, mixed>}>|null
     */
    private ?array $presets = null;

    /**
     * @param Dir $moduleDir
     * @param File $file
     * @param ProfileJson $profileJson
     * @param ProfileRepositoryInterface $profiles
     * @param string|null $folder presets folder (tests); default: the module's presets folder
     */
    public function __construct(
        private readonly Dir $moduleDir,
        private readonly File $file,
        private readonly ProfileJson $profileJson,
        private readonly ProfileRepositoryInterface $profiles,
        private readonly ?string $folder = null
    ) {
    }

    /**
     * @return array<string, array{label: string, description: string, notes: string}> code => texts
     */
    public function all(): array
    {
        return array_map(
            static fn (array $preset): array => array_intersect_key($preset, array_flip(['label', 'description', 'notes'])),
            $this->load()
        );
    }

    /**
     * The preset's profile data (one profile, as in a profile JSON file).
     *
     * @param string $code
     * @return array<string, mixed>
     * @throws \InvalidArgumentException
     */
    public function profileData(string $code): array
    {
        $preset = $this->load()[$code] ?? null;
        if ($preset === null) {
            throw new \InvalidArgumentException(sprintf('There is no preset "%s".', $code));
        }

        return $preset['json']['profiles'][0];
    }

    /**
     * Creates an inactive profile from the preset; its name gets a number when the name is taken.
     *
     * @param string $code
     * @return ProfileInterface
     * @throws \InvalidArgumentException
     */
    public function create(string $code): ProfileInterface
    {
        $row = $this->profileData($code);
        $base = (string) $row['name'];
        $name = $base;
        for ($i = 2; $this->nameTaken($name); $i++) {
            $name = $base . ' ' . $i;
        }
        $row['name'] = $name;
        $row['is_active'] = false;
        [[$profile]] = $this->profileJson->prepare((string) json_encode(['openimport' => 1, 'profiles' => [$row]]));
        $this->profileJson->save([[$profile, false]]);

        return $this->profiles->getByName($name);
    }

    private function nameTaken(string $name): bool
    {
        try {
            $this->profiles->getByName($name);

            return true;
        } catch (NoSuchEntityException) {
            return false;
        }
    }

    /**
     * @return array<string, array{label: string, description: string, notes: string, json: array<string, mixed>}>
     */
    private function load(): array
    {
        if ($this->presets !== null) {
            return $this->presets;
        }
        $folder = $this->folder ?? $this->moduleDir->getDir('Iranimij_OpenImport') . '/presets';
        $this->presets = [];
        $files = $this->file->isDirectory($folder) ? $this->file->readDirectory($folder) : [];
        sort($files);
        foreach ($files as $path) {
            if (!str_ends_with($path, '.json')) {
                continue;
            }
            $json = json_decode($this->file->fileGetContents($path), true);
            $meta = is_array($json) ? ($json['preset'] ?? null) : null;
            if (!is_array($meta) || !isset($meta['code'], $json['profiles'][0]) || !is_array($json['profiles'][0])) {
                continue;
            }
            $this->presets[(string) $meta['code']] = [
                'label' => (string) ($meta['label'] ?? $meta['code']),
                'description' => (string) ($meta['description'] ?? ''),
                'notes' => (string) ($meta['notes'] ?? ''),
                'json' => $json,
            ];
        }

        return $this->presets;
    }
}
