<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Stock;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Entity\EntityRegistry;
use Iranimij\OpenImport\Model\Pipeline\PipelineException;
use Iranimij\OpenImport\Model\Pipeline\Step\AbstractRowStep;
use Iranimij\OpenImport\Model\Pipeline\Step\StepRecord;

/**
 * "Quantities are changes": the quantity column holds deliveries and sales (+5, -3; a plain 4 adds 4), and the row
 * gets the current quantity plus the change. Works for products, Stock & prices (default stock, column qty) and
 * stock per source (MSI, column quantity per SKU and source).
 *
 * Current quantities are read per batch; live runs hold the lock of Magento's entity, so two runs never add to the
 * same quantity at once. "Skip unchanged rows" is refused: the same +5 in the next file is a new delivery.
 */
class StockChangeStep extends AbstractRowStep
{
    public const OPTION_MODE = 'qty_mode';
    public const MODE_ADD = 'add';

    private const ENTITIES = ['catalog_product', EntityRegistry::STOCK_PRICE, EntityRegistry::STOCK_SOURCES];

    private string $column = 'qty';

    private bool $sources = false;

    private int $changed = 0;

    /**
     * @var array<string, float> quantities this run already computed (a SKU twice in the file adds up)
     */
    private array $running = [];

    /**
     * @param QuantityReader $reader
     * @param QuantityChange $change
     */
    public function __construct(
        private readonly QuantityReader $reader,
        private readonly QuantityChange $change
    ) {
    }

    /**
     * @inheritDoc
     */
    public function begin(ProfileInterface $profile, string $behavior, bool $dryRun): bool
    {
        if (!in_array($profile->getEntity(), self::ENTITIES, true)
            || ($profile->getImportOptions()[self::OPTION_MODE] ?? '') !== self::MODE_ADD
            || $behavior === ProfileInterface::BEHAVIOR_DELETE) {
            return false;
        }
        if ($profile->isDelta()) {
            throw new PipelineException(
                '"Skip unchanged rows" cannot be used with quantities as changes: the same +5 in the next file is'
                . ' a new delivery. Switch one of them off.'
            );
        }
        $this->sources = $profile->getEntity() === EntityRegistry::STOCK_SOURCES;
        $this->column = $this->sources ? 'quantity' : 'qty';
        $this->changed = 0;
        $this->running = [];

        return true;
    }

    /**
     * @inheritDoc
     */
    public function columns(array $columns): array
    {
        if (!in_array($this->column, $columns, true)) {
            throw new PipelineException(sprintf(
                'Map the quantity (%s) in step 4: with quantities as changes it holds the change.',
                $this->column
            ));
        }

        return $columns;
    }

    /**
     * @inheritDoc
     */
    public function apply(array $records, \Closure $tick): void
    {
        $changes = [];
        foreach ($records as $index => $record) {
            $value = trim((string) ($record->rows[0][$this->column] ?? ''));
            if ($value === '') {
                continue;
            }
            $delta = $this->change->parse($value);
            if ($delta === null) {
                $record->fail(sprintf('"%s" is not a change of quantity (+5, -3 or 4).', $value), $this->column);
                continue;
            }
            $changes[$index] = $delta;
        }
        if ($changes === []) {
            return;
        }
        $current = $this->current(array_intersect_key($records, $changes));
        foreach ($changes as $index => $delta) {
            $record = $records[$index];
            $key = $this->key($record);
            $quantity = ($this->running[$key] ?? $current[$key] ?? 0.0) + $delta;
            $this->running[$key] = $quantity;
            $record->rows[0][$this->column] = $this->change->format($quantity);
            $this->changed++;
        }
    }

    /**
     * @inheritDoc
     */
    public function summary(): string
    {
        return $this->changed === 0 ? '' : (string) __('Quantities changed by the file: %1.', $this->changed);
    }

    /**
     * @param StepRecord[] $records
     * @return array<string, float>
     */
    private function current(array $records): array
    {
        if (!$this->sources) {
            return $this->reader->products(array_map(static fn (StepRecord $r): string => $r->identifier, $records));
        }

        return $this->reader->sources(array_values(array_map(
            static fn (StepRecord $r): array => [
                (string) ($r->rows[0]['sku'] ?? ''),
                (string) ($r->rows[0]['source_code'] ?? ''),
            ],
            $records
        )));
    }

    private function key(StepRecord $record): string
    {
        $sku = mb_strtolower((string) ($record->rows[0]['sku'] ?? $record->identifier));

        return $this->sources ? $sku . '|' . ($record->rows[0]['source_code'] ?? '') : $sku;
    }
}
