<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Configurable;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Entity\RecordReaderPool;
use Iranimij\OpenImport\Model\Image\ImageColumns;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Pipeline\Step\AbstractRowStep;
use Iranimij\OpenImport\Model\Pipeline\Step\StepRecord;

/**
 * Builds configurable products from simple rows: rows with the same value in the mapped "Parent SKU" become the
 * variants of one configurable product, which OpenImport adds after the last row (with configurable_variations).
 *
 * - The parent takes the values of a variant (attribute set, websites, categories, description, price, pictures),
 *   its name from "Parent name" or from the words all variant names start with, visibility "Catalog, Search".
 * - Variants become "Not Visible Individually" (unless the profile keeps the mapped visibility); new variants get the
 *   URL key "<name>-<sku>" when the mapping has no URL key, so equal names do not collide.
 * - Every variant needs an existing option of every variation attribute, and no two variants of a parent may share
 *   all values.
 * - The parent lists every valid variant in the file, also those "skip unchanged rows" leaves out: Magento drops
 *   links a parent's list does not name.
 * - A file may have the parent's own row (product type "configurable", no parent SKU, as WooCommerce exports
 *   variable products): it is taken over and sent once, with the variations; its own name, URL key, visibility and
 *   stock stay. A simple row with a parent's SKU is a conflict: that parent is not built.
 */
class ConfigurableStep extends AbstractRowStep
{
    public const OPTION_ENABLED = 'configurable';
    public const OPTION_ATTRIBUTES = 'configurable_attributes';
    public const OPTION_VISIBILITY = 'configurable_child_visibility';
    public const VISIBILITY_KEEP = 'keep';
    public const COLUMN_PARENT_SKU = 'configurable:parent_sku';
    public const COLUMN_PARENT_NAME = 'configurable:parent_name';
    public const HIDDEN = 'Not Visible Individually';

    /**
     * @var string[]
     */
    private array $attributes = [];

    private bool $hideVariants = true;

    private bool $urlKeys = false;

    /**
     * @var string[] the columns core receives (parent rows have exactly these, so their delta hash is stable)
     */
    private array $columns = [];

    /**
     * @var array<string, array{sku: string, line: int, template: array<int, array<string, string>>|null,
     *     templateSku: string, applied: bool, variants: array<string, array<string, string>>,
     *     names: array<string, string[]>, parentNames: array<string, string>}>
     *     lower-cased parent SKU => group; the template is the first valid variant in the file
     */
    private array $groups = [];

    /**
     * @var array<string, string> lower-cased variant SKU => lower-cased parent SKU
     */
    private array $parentOf = [];

    /**
     * @var array<string, array{sku: string, line: int, rows: array<int, array<string, string>>, applied: bool}>
     *     lower-cased SKU => the parent's own row from the file
     */
    private array $own = [];

    /**
     * @var array<string, string> lower-cased SKU => SKU of rows that are neither variants nor parents
     */
    private array $plain = [];

    /**
     * @var string[] parents not built because the file has a simple row with their SKU
     */
    private array $conflicts = [];

    /**
     * @var array<string, array{0: string, 1: string}> lower-cased variant SKU => [problem, column]
     */
    private array $problems = [];

    private int $built = 0;

    private int $variantCount = 0;

    /**
     * @param ConfigurableAttributes $configurableAttributes
     * @param ParentBuilder $builder
     * @param RecordReaderPool $recordReaders
     * @param ImageColumns $imageColumns
     * @param UrlKey $urlKey
     */
    public function __construct(
        private readonly ConfigurableAttributes $configurableAttributes,
        private readonly ParentBuilder $builder,
        private readonly RecordReaderPool $recordReaders,
        private readonly ImageColumns $imageColumns,
        private readonly UrlKey $urlKey
    ) {
    }

    /**
     * @inheritDoc
     */
    public function begin(ProfileInterface $profile, string $behavior, bool $dryRun): bool
    {
        $options = $profile->getImportOptions();
        if ($profile->getEntity() !== 'catalog_product' || empty($options[self::OPTION_ENABLED])
            || $behavior === ProfileInterface::BEHAVIOR_DELETE) {
            return false;
        }
        $this->attributes = array_values(array_unique(array_filter(
            array_map('trim', explode(',', (string) ($options[self::OPTION_ATTRIBUTES] ?? ''))),
            static fn (string $code): bool => $code !== ''
        )));
        if ($this->attributes === []) {
            throw new PipelineException(
                'Choose the variation attributes (step 5) to build configurable products, or switch it off.'
            );
        }
        $problems = array_filter(array_map(
            fn (string $code): ?string => $this->configurableAttributes->problem($code),
            $this->attributes
        ));
        if ($problems !== []) {
            throw new PipelineException(implode(' ', $problems));
        }
        $this->hideVariants = ($options[self::OPTION_VISIBILITY] ?? '') !== self::VISIBILITY_KEEP;
        $this->groups = [];
        $this->parentOf = [];
        $this->own = [];
        $this->plain = [];
        $this->conflicts = [];
        $this->problems = [];
        $this->built = 0;
        $this->variantCount = 0;

        return true;
    }

    /**
     * @inheritDoc
     */
    public function columns(array $columns): array
    {
        if (!in_array(self::COLUMN_PARENT_SKU, $columns, true)) {
            throw new PipelineException(
                'Map a column to "Parent SKU (configurable products)" in step 4, or switch off "Build configurable'
                . ' products".'
            );
        }
        foreach ($this->attributes as $attribute) {
            if (!in_array($attribute, $columns, true)) {
                throw new PipelineException(sprintf(
                    'Map the variation attribute "%s" in step 4: every variant needs its value.',
                    $attribute
                ));
            }
        }
        $this->urlKeys = !in_array('url_key', $columns, true);
        $columns = array_values(array_diff($columns, [self::COLUMN_PARENT_SKU, self::COLUMN_PARENT_NAME]));
        $added = ['product_type', ParentBuilder::COLUMN_VARIATIONS, 'visibility', 'url_key'];

        return $this->columns = array_values(array_unique(array_merge($columns, $added)));
    }

    /**
     * @inheritDoc
     */
    public function observe(array $records): void
    {
        foreach ($records as $record) {
            $main = $record->rows[0] ?? [];
            $parent = trim((string) ($main[self::COLUMN_PARENT_SKU] ?? ''));
            if ($record->identifier === '') {
                continue;
            }
            if ($parent === '') {
                $sku = mb_strtolower($record->identifier);
                if ($this->isOwnParentRow($main)) {
                    $this->own[$sku] = ['sku' => $record->identifier, 'line' => $record->line, 'rows' => $record->rows,
                        'applied' => false];
                } else {
                    $this->plain[$sku] = $record->identifier;
                }
                continue;
            }
            $key = mb_strtolower($parent);
            $child = mb_strtolower($record->identifier);
            $this->groups[$key] ??= [
                'sku' => $parent,
                'line' => $record->line,
                'template' => null,
                'templateSku' => '',
                'applied' => false,
                'variants' => [],
                'names' => [],
                'parentNames' => [],
            ];
            $this->parentOf[$child] = $key;
            unset($this->problems[$child], $this->groups[$key]['variants'][$record->identifier]);
            $problem = $this->problem($record, $key, $child);
            if ($problem !== null) {
                $this->problems[$child] = $problem;
                continue;
            }
            $values = [];
            foreach ($this->attributes as $attribute) {
                $values[$attribute] = trim((string) ($main[$attribute] ?? ''));
            }
            $this->groups[$key]['variants'][$record->identifier] = $values;
            if ($this->groups[$key]['template'] === null) {
                $this->groups[$key]['template'] = $record->rows;
                $this->groups[$key]['templateSku'] = $child;
            }
            foreach ($record->rows as $row) {
                $store = (string) ($row['store_view_code'] ?? '');
                $name = trim((string) ($row['name'] ?? ''));
                if ($name !== '') {
                    $this->groups[$key]['names'][$store][] = $name;
                }
                $parentName = trim((string) ($row[self::COLUMN_PARENT_NAME] ?? ''));
                if ($parentName !== '') {
                    $this->groups[$key]['parentNames'][$store] = $parentName;
                }
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function apply(array $records, \Closure $tick): void
    {
        $variants = [];
        foreach ($records as $record) {
            $child = mb_strtolower($record->identifier);
            if (isset($this->own[$child]) && $this->isOwnParentRow($record->rows[0] ?? [])
                && trim((string) ($record->rows[0][self::COLUMN_PARENT_SKU] ?? '')) === '') {
                // Sent once at the end, with its variations.
                $this->own[$child]['rows'] = $record->rows;
                $this->own[$child]['applied'] = true;
                $record->absorb();
                continue;
            }
            if (!isset($this->parentOf[$child])) {
                continue;
            }
            if (isset($this->problems[$child])) {
                [$message, $column] = $this->problems[$child];
                $record->fail($message, $column);
                continue;
            }
            $variants[$child] = $record;
        }
        if ($variants === []) {
            return;
        }
        $existing = $this->urlKeys
            ? ($this->recordReaders->get('catalog_product')?->existing(array_map(
                static fn (StepRecord $record): string => $record->identifier,
                array_values($variants)
            )) ?? [])
            : [];
        foreach ($variants as $child => $record) {
            if ($this->hideVariants) {
                $record->rows[0]['visibility'] = self::HIDDEN;
            }
            if ($this->urlKeys && !isset($existing[$child])) {
                $record->rows[0]['url_key'] = $this->urlKey->format(
                    (string) ($record->rows[0]['name'] ?? '') . '-' . $record->identifier
                );
            }
            $group = &$this->groups[$this->parentOf[$child]];
            if ($group['templateSku'] === $child) {
                // The template passed the steps: it carries downloaded pictures, the parent copies those.
                $group['template'] = $record->rows;
                $group['applied'] = true;
            }
            unset($group);
        }
    }

    /**
     * @inheritDoc
     */
    public function finish(\Closure $tick): array
    {
        $clear = array_merge($this->imageColumns->single(), ImageColumns::LISTS);
        $records = [];
        foreach (array_unique(array_merge(array_keys($this->groups), array_keys($this->own))) as $key) {
            $group = $this->groups[$key] ?? null;
            $own = $this->own[$key] ?? null;
            if ($own === null && isset($this->plain[$key])) {
                $this->conflicts[] = $this->plain[$key];
                continue;
            }
            if ($own !== null) {
                $variants = $group['variants'] ?? [];
                $records[] = new StepRecord($own['line'], $own['sku'], $this->builder->build(
                    $own['sku'],
                    array_map(fn (array $row): array => $this->normalize($row), $own['rows']),
                    $variants,
                    $this->attributes,
                    [],
                    $own['applied'] ? [] : $clear,
                    true
                ));
            } elseif ($group !== null && $group['variants'] !== [] && $group['template'] !== null) {
                $variants = $group['variants'];
                $names = $group['parentNames'];
                foreach ($group['names'] as $store => $list) {
                    $names[$store] ??= $this->builder->commonName($list);
                }
                $records[] = new StepRecord($group['line'], $group['sku'], $this->builder->build(
                    $group['sku'],
                    array_map(fn (array $row): array => $this->normalize($row), $group['template']),
                    $variants,
                    $this->attributes,
                    $names,
                    $group['applied'] ? [] : $clear
                ));
            } else {
                continue;
            }
            $this->built++;
            $this->variantCount += count($variants);
        }

        return $records;
    }

    /**
     * @inheritDoc
     */
    public function summary(): string
    {
        if ($this->built === 0 && $this->problems === [] && $this->conflicts === []) {
            return '';
        }
        $summary = (string) __(
            'Configurable products: %1 built from %2 variants.',
            $this->built,
            $this->variantCount
        );
        if ($this->problems !== []) {
            $summary .= ' ' . __('%1 variant(s) left out, see the errors.', count($this->problems));
        }
        if ($this->conflicts !== []) {
            $summary .= ' ' . __(
                'Not built because the file has a simple product with the parent SKU: %1.',
                implode(', ', array_slice($this->conflicts, 0, 10))
            );
        }

        return $summary;
    }

    /**
     * @param StepRecord $record
     * @param string $parent lower-cased parent SKU
     * @param string $child lower-cased variant SKU
     * @return array{0: string, 1: string}|null problem and column
     */
    private function problem(StepRecord $record, string $parent, string $child): ?array
    {
        if ($child === $parent) {
            return ['The parent SKU is the row\'s own SKU; a variant needs another parent.', self::COLUMN_PARENT_SKU];
        }
        if (isset($this->groups[$child]) || isset($this->own[$child])) {
            return ['This SKU is the parent of other rows; it cannot be a variant too.', self::COLUMN_PARENT_SKU];
        }
        $main = $record->rows[0] ?? [];
        $values = [];
        foreach ($this->attributes as $attribute) {
            $value = trim((string) ($main[$attribute] ?? ''));
            if ($value === '') {
                return [sprintf('The variation attribute "%s" is empty.', $attribute), $attribute];
            }
            if (!$this->configurableAttributes->hasOption($attribute, $value)) {
                return [
                    sprintf(
                        '"%s" is not an option of %s; add it first (attribute import or Stores › Attributes).',
                        $value,
                        $attribute
                    ),
                    $attribute,
                ];
            }
            $values[$attribute] = mb_strtolower($value);
        }
        foreach ($this->groups[$parent]['variants'] as $sku => $other) {
            if (array_map('mb_strtolower', $other) === $values) {
                $message = sprintf(
                    'Another variant of %s, %s, has the same %s.',
                    $this->groups[$parent]['sku'],
                    $sku,
                    implode(', ', $this->attributes)
                );

                return [$message, $this->attributes[0]];
            }
        }

        return null;
    }

    /**
     * The row with exactly the columns core receives, in their order.
     *
     * @param array<string, string> $row
     * @return array<string, string>
     */
    private function normalize(array $row): array
    {
        $normal = [];
        foreach ($this->columns as $column) {
            $normal[$column] = (string) ($row[$column] ?? '');
        }
        // The builder tells store-view rows by it.
        $normal['store_view_code'] = (string) ($row['store_view_code'] ?? '');

        return $normal;
    }

    /**
     * A row that is a configurable product itself (product type "configurable"), not a variant.
     *
     * @param array<string, string> $row
     * @return bool
     */
    private function isOwnParentRow(array $row): bool
    {
        return mb_strtolower(trim((string) ($row['product_type'] ?? ''))) === 'configurable';
    }
}
