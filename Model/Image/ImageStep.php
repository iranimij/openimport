<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Image;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Pipeline\Step\AbstractRowStep;
use Iranimij\OpenImport\Model\Pipeline\Step\StepRecord;

/**
 * Product pictures given as web addresses are downloaded by OpenImport (ImageFetcher) and handed to core as files
 * in the import images folder. Core never downloads them itself: its own download has no SSRF guard, no size cap
 * and no cache.
 *
 * A picture that cannot be read fails its row, or, with the profile option images_on_error = skip, the row is
 * imported without that picture (the run message names them).
 */
class ImageStep extends AbstractRowStep
{
    public const OPTION_ON_ERROR = 'images_on_error';
    public const ON_ERROR_ERROR = 'error';
    public const ON_ERROR_SKIP = 'skip';

    private const NAMED = 5;

    private bool $skip = false;

    /**
     * @var string[] "SKU-1 (https://…): reason", the first pictures left out
     */
    private array $leftOut = [];

    private int $leftOutCount = 0;

    private bool $seen = false;

    private float $lastTick = 0.0;

    /**
     * @param ImageFetcher $fetcher
     * @param ImageColumns $columns
     */
    public function __construct(
        private readonly ImageFetcher $fetcher,
        private readonly ImageColumns $columns
    ) {
    }

    /**
     * @inheritDoc
     */
    public function begin(ProfileInterface $profile, string $behavior, bool $dryRun): bool
    {
        if ($profile->getEntity() !== 'catalog_product' || $behavior === ProfileInterface::BEHAVIOR_DELETE) {
            return false;
        }
        $this->skip = ($profile->getImportOptions()[self::OPTION_ON_ERROR] ?? '') === self::ON_ERROR_SKIP;
        $this->leftOut = [];
        $this->leftOutCount = 0;
        $this->seen = false;
        $this->fetcher->begin();

        return true;
    }

    /**
     * @inheritDoc
     */
    public function apply(array $records, \Closure $tick): void
    {
        $single = $this->columns->single();
        foreach ($records as $record) {
            foreach ($record->rows as $index => $row) {
                foreach ($row as $column => $value) {
                    $isList = in_array($column, ImageColumns::LISTS, true);
                    if (trim($value) === '' || (!$isList && !in_array($column, $single, true))) {
                        continue;
                    }
                    if (!str_contains($value, '://') && !$this->unsafeName($value, $isList)) {
                        continue;
                    }
                    if (in_array($column, $single, true)) {
                        $row[$column] = $this->localize($record, $column, trim($value), $tick);
                    } elseif (in_array($column, ImageColumns::LISTS, true)) {
                        $items = [];
                        foreach (array_map('trim', explode(',', $value)) as $item) {
                            $items[] = $this->localize($record, $column, $item, $tick);
                        }
                        $row[$column] = implode(',', array_filter($items, static fn (string $i): bool => $i !== ''));
                    }
                    if ($record->isFailed()) {
                        continue 3;
                    }
                }
                $record->rows[$index] = $row;
            }
        }
        $this->fetcher->flush();
    }

    /**
     * @inheritDoc
     */
    public function summary(): string
    {
        if (!$this->seen) {
            return '';
        }
        $counts = $this->fetcher->counts();
        $summary = (string) __(
            'Pictures: %1 downloaded, %2 unchanged, %3 could not be read.',
            $counts['downloaded'],
            $counts['unchanged'],
            $counts['failed']
        );
        if ($this->leftOut !== []) {
            $summary .= "\n" . (string) __('Imported without the picture: %1', implode('; ', $this->leftOut))
                . ($this->leftOutCount > count($this->leftOut)
                    ? ' ' . __('and %1 more', $this->leftOutCount - count($this->leftOut)) : '');
        }

        return $summary;
    }

    /**
     * @param StepRecord $record
     * @param string $column
     * @param string $value one picture
     * @param \Closure $tick
     * @return string the file for core, '' when the picture is left out
     */
    private function localize(StepRecord $record, string $column, string $value, \Closure $tick): string
    {
        $web = preg_match('#^https?://#i', $value) === 1;
        if (!$web && !$this->unsafeName($value, false)) {
            return $value;
        }
        $this->seen = $this->seen || $web;
        try {
            if (!$web) {
                throw new PipelineException((string) __(
                    'only web addresses (http, https) and file names inside var/import/images are read.'
                ));
            }
            $file = $this->fetcher->fetch($value);
            $this->tick($tick);

            return $file;
        } catch (PipelineException $exception) {
            $this->tick($tick);
            $message = $exception->getMessage();
            if (!$this->skip) {
                $record->fail(sprintf('Picture %s: %s', $value, $message), $column);

                return $value;
            }
            $record->markIncomplete();
            $this->leftOutCount++;
            if (count($this->leftOut) < self::NAMED) {
                $this->leftOut[] = sprintf('%s (%s): %s', $record->identifier, $value, $message);
            }

            return '';
        }
    }

    /**
     * A cell that is neither a web address nor a file name in the import images folder: another scheme (file://,
     * ftp://), a drive or network path, or "../" out of the folder. A leading "/" is fine: Magento reads it inside
     * the folder, and exports write gallery paths that way.
     *
     * @param string $value one picture, or a comma-separated list
     * @param bool $list
     * @return bool
     */
    private function unsafeName(string $value, bool $list): bool
    {
        foreach ($list ? explode(',', $value) : [$value] as $item) {
            $item = trim($item);
            if ($item === '' || preg_match('#^https?://#i', $item) === 1) {
                continue;
            }
            if (str_contains($item, '://') || str_starts_with($item, '\\')
                || preg_match('#^[a-z]:#i', $item) === 1 || preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $item) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keeps the run alive (and cancellable) during long downloads, at most once a second.
     *
     * @param \Closure $tick
     * @return void
     */
    private function tick(\Closure $tick): void
    {
        $now = microtime(true);
        if ($now - $this->lastTick >= 1.0) {
            $this->lastTick = $now;
            $tick();
        }
    }
}
