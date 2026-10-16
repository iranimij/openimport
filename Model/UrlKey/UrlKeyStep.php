<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\UrlKey;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Configurable\UrlKey;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Pipeline\Step\AbstractRowStep;

/**
 * URL keys from a pattern ("{name}-{sku}") for product rows whose URL key cell is empty, and 301 redirects from old
 * URLs when a URL key changes (Magento's import column save_rewrites_history).
 *
 * A key the pattern makes twice in a run, or that another product has, fails the row with the other SKU named.
 */
class UrlKeyStep extends AbstractRowStep
{
    public const OPTION_PATTERN = 'url_key_pattern';
    public const OPTION_REDIRECTS = 'url_redirects';
    public const COLUMN_HISTORY = 'save_rewrites_history';

    private string $pattern = '';

    private bool $redirects = false;

    /**
     * @var array<string, string> URL key => SKU that got it in this run
     */
    private array $made = [];

    private int $count = 0;

    /**
     * @param UrlKeyPattern $patterns
     * @param UrlKey $urlKey
     * @param UrlKeyOwners $owners
     */
    public function __construct(
        private readonly UrlKeyPattern $patterns,
        private readonly UrlKey $urlKey,
        private readonly UrlKeyOwners $owners
    ) {
    }

    /**
     * @inheritDoc
     */
    public function begin(ProfileInterface $profile, string $behavior, bool $dryRun): bool
    {
        $options = $profile->getImportOptions();
        $this->pattern = trim((string) ($options[self::OPTION_PATTERN] ?? ''));
        $this->redirects = !empty($options[self::OPTION_REDIRECTS]);
        if ($profile->getEntity() !== 'catalog_product' || $behavior === ProfileInterface::BEHAVIOR_DELETE
            || ($this->pattern === '' && !$this->redirects)) {
            return false;
        }
        if ($this->pattern !== '' && $this->patterns->fields($this->pattern) === []) {
            throw new PipelineException(sprintf(
                'The URL key pattern "%s" names no field; write fields in braces, for example {name}-{sku}.',
                $this->pattern
            ));
        }
        $this->made = [];
        $this->count = 0;

        return true;
    }

    /**
     * @inheritDoc
     */
    public function columns(array $columns): array
    {
        if ($this->pattern !== '') {
            $missing = array_diff($this->patterns->fields($this->pattern), $columns);
            if ($missing !== []) {
                throw new PipelineException(sprintf(
                    'The URL key pattern uses %s, which the mapping does not set. Map it in step 4.',
                    implode(', ', $missing)
                ));
            }
            $columns[] = 'url_key';
        }
        if ($this->redirects) {
            $columns[] = self::COLUMN_HISTORY;
        }

        return array_values(array_unique($columns));
    }

    /**
     * @inheritDoc
     */
    public function apply(array $records, \Closure $tick): void
    {
        $keys = [];
        foreach ($records as $index => $record) {
            foreach ($record->rows as $row => $values) {
                if ($this->redirects) {
                    $record->rows[$row][self::COLUMN_HISTORY] = '1';
                }
                if ($this->pattern === '' || trim((string) ($values['url_key'] ?? '')) !== '') {
                    continue;
                }
                // Store-view rows get their own key only when they have their own values for the pattern.
                $text = $row === 0
                    ? $this->patterns->fill($this->pattern, $values)
                    : ($this->hasOwnValues($values, $record->rows[0])
                        ? $this->patterns->fill($this->pattern, $values, $record->rows[0]) : '');
                $key = $text === '' ? '' : $this->urlKey->format($text);
                if ($key === '') {
                    continue;
                }
                $record->rows[$row]['url_key'] = $key;
                if ($row === 0) {
                    $keys[$index] = $key;
                }
            }
        }
        if ($keys === []) {
            return;
        }
        $owners = $this->owners->owners(array_values($keys));
        foreach ($keys as $index => $key) {
            $record = $records[$index];
            $sku = $record->identifier;
            $other = $this->made[$key] ?? $owners[$key] ?? null;
            if ($other !== null && mb_strtolower($other) !== mb_strtolower($sku)) {
                $record->fail(sprintf('The URL key "%s" belongs to %s already.', $key, $other), 'url_key');
                continue;
            }
            $this->made[$key] = $sku;
            $this->count++;
        }
    }

    /**
     * Whether a store-view row has a value of its own for a field of the pattern.
     *
     * @param array<string, string> $row
     * @param array<string, string> $default
     * @return bool
     */
    private function hasOwnValues(array $row, array $default): bool
    {
        foreach ($this->patterns->fields($this->pattern) as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '' && $value !== trim((string) ($default[$field] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function summary(): string
    {
        return $this->count === 0 ? '' : (string) __('URL keys made from the pattern: %1.', $this->count);
    }
}
