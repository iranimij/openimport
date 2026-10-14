<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Export;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Api\Data\RunInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * The export file's name from export_options.file_name, with placeholders: {profile}, {entity}, {date}
 * (YYYY-MM-DD), {time} (HHMMSS), {datetime} (YYYY-MM-DD_HHMMSS), {run} (run id), {ext}. Default
 * "{entity}-{datetime}.{ext}". Times are the store's. Unsafe characters become "_".
 */
class FileNamer
{
    public const DEFAULT = '{entity}-{datetime}.{ext}';

    /**
     * @param TimezoneInterface $timezone
     */
    public function __construct(
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * @param ProfileInterface $profile
     * @param RunInterface $run
     * @param string $extension
     * @return string
     */
    public function name(ProfileInterface $profile, RunInterface $run, string $extension): string
    {
        $pattern = trim((string) ($profile->getExportOptions()['file_name'] ?? ''));
        if ($pattern === '') {
            $pattern = self::DEFAULT;
        }
        $now = $this->timezone->date();
        $slug = static fn (string $text): string => trim((string) preg_replace('/[^A-Za-z0-9._-]+/u', '_', $text), '_');
        $name = strtr($pattern, [
            '{profile}' => $slug($profile->getName()),
            '{entity}' => $slug($profile->getEntity()),
            '{date}' => $now->format('Y-m-d'),
            '{time}' => $now->format('His'),
            '{datetime}' => $now->format('Y-m-d_His'),
            '{run}' => (string) $run->getRunId(),
            '{ext}' => $extension,
        ]);
        $name = (new \SplFileInfo(str_replace('\\', '/', $name)))->getFilename();
        $name = trim((string) preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $name), '. ');
        if ($name === '' || $name === '.' . $extension) {
            $name = 'export.' . $extension;
        }
        if (!str_ends_with(strtolower($name), '.' . $extension)) {
            $name .= '.' . $extension;
        }

        return $name;
    }
}
