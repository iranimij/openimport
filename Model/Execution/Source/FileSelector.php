<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Execution\Source;

use Iranimij\OpenImport\Model\Pipeline\PipelineException;

/**
 * Picks the file a folder, SFTP or FTP source reads: the files whose name matches the pattern (`*` any text,
 * `?` one character, case-insensitive; several patterns separated by commas), then the newest, the oldest, or the
 * first or last by name.
 */
class FileSelector
{
    public const PICK_NEWEST = 'newest';
    public const PICK_OLDEST = 'oldest';
    public const PICK_FIRST_NAME = 'first_name';
    public const PICK_LAST_NAME = 'last_name';
    /**
     * Every matching file, one run each, in name order (the first one when a single file is read).
     */
    public const PICK_ALL = 'all';
    public const PICKS = [self::PICK_NEWEST, self::PICK_OLDEST, self::PICK_FIRST_NAME, self::PICK_LAST_NAME,
        self::PICK_ALL];

    /**
     * @param array<string, int|null> $files name => modification time (null when the server does not tell)
     * @param string $pattern
     * @return array<string, int|null> the matching files
     */
    public function matching(array $files, string $pattern): array
    {
        $regexes = [];
        foreach (explode(',', $pattern === '' ? '*' : $pattern) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $regexes[] = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($part, '/')) . '$/iu';
            }
        }

        return array_filter($files, static function ($time, $name) use ($regexes): bool {
            foreach ($regexes as $regex) {
                if (preg_match($regex, (string) $name) === 1) {
                    return true;
                }
            }

            return false;
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The matching files in name order (natural, ignoring case).
     *
     * @param array<string, int|null> $files name => modification time
     * @param string $pattern
     * @return string[]
     */
    public function ordered(array $files, string $pattern): array
    {
        $names = array_map('strval', array_keys($this->matching($files, $pattern)));
        usort($names, 'strnatcasecmp');

        return $names;
    }

    /**
     * @param array<string, int|null> $files name => modification time
     * @param string $pattern
     * @param string $pick
     * @return string the chosen name
     * @throws PipelineException when no file matches
     */
    public function pick(array $files, string $pattern, string $pick): string
    {
        $matching = $this->matching($files, $pattern);
        if ($matching === []) {
            throw new PipelineException(sprintf(
                'No file matches "%s" (%d file(s) in the folder).',
                $pattern === '' ? '*' : $pattern,
                count($files)
            ));
        }
        $names = array_map('strval', array_keys($matching));
        usort($names, static function (string $a, string $b) use ($matching, $pick): int {
            if (in_array($pick, [self::PICK_NEWEST, self::PICK_OLDEST], true)) {
                $byTime = ($matching[$a] ?? 0) <=> ($matching[$b] ?? 0);
                if ($byTime !== 0) {
                    return $pick === self::PICK_NEWEST ? -$byTime : $byTime;
                }
            }
            $byName = strnatcasecmp($a, $b);

            // PICK_ALL reads the first by name.
            return $pick === self::PICK_LAST_NAME || $pick === self::PICK_NEWEST ? -$byName : $byName;
        });

        return $names[0];
    }
}
