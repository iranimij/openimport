<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Missing;

use Iranimij\OpenImport\Api\Data\ProfileInterface;
use Iranimij\OpenImport\Model\Bridge\ImporterPool;
use Iranimij\OpenImport\Model\Bridge\ImportRequest;
use Iranimij\OpenImport\Model\Pipeline\Step\AbstractRowStep;
use Iranimij\OpenImport\Model\Pipeline\Step\PostImportOutcome;
use Iranimij\OpenImport\Model\Pipeline\Step\PostImportPlan;
use Iranimij\OpenImport\Model\Pipeline\Step\PostImportStepInterface;
use Iranimij\OpenImport\Model\Source\MappedSource;
use Magento\ImportExport\Model\Import;

/**
 * "The file is the complete list": products in the profile's scope that the file does not name are disabled, set out
 * of stock or deleted after the import, through Magento's import like the file's rows.
 *
 * - Every SKU in the file counts as named: rows filtered out, rows with errors, rows "skip unchanged rows" leaves out
 *   and configurable parents OpenImport builds.
 * - A run with row errors changes nothing (a row with an error may name a product).
 * - Only products the action changes are counted: disable takes enabled products, out of stock takes products in
 *   stock; the safety limit compares them with all products in the scope.
 */
class MissingProductsStep extends AbstractRowStep implements PostImportStepInterface
{
    public const OPTION_ACTION = 'missing_action';
    public const OPTION_SCOPE = 'missing_scope';
    public const OPTION_WEBSITE = 'missing_website';
    public const OPTION_ATTRIBUTE = 'missing_attribute';
    public const OPTION_VALUE = 'missing_value';
    public const ACTION_DISABLE = 'disable';
    public const ACTION_OUT_OF_STOCK = 'out_of_stock';
    public const ACTION_DELETE = 'delete';

    private const NAMED = 10;

    private const BEHAVIORS = [ProfileInterface::BEHAVIOR_ADD_UPDATE, ProfileInterface::BEHAVIOR_ONLY_UPDATE];

    private string $action = '';

    /**
     * @var array<string, string>
     */
    private array $scope = [];

    /**
     * @var array<string, true> lower-cased SKUs the file names
     */
    private array $named = [];

    /**
     * @param MissingScope $missingScope
     * @param ImporterPool $importers
     */
    public function __construct(
        private readonly MissingScope $missingScope,
        private readonly ImporterPool $importers
    ) {
    }

    /**
     * @inheritDoc
     */
    public function begin(ProfileInterface $profile, string $behavior, bool $dryRun): bool
    {
        $options = $profile->getImportOptions();
        $action = (string) ($options[self::OPTION_ACTION] ?? '');
        if ($profile->getEntity() !== 'catalog_product'
            || !in_array($action, [self::ACTION_DISABLE, self::ACTION_OUT_OF_STOCK, self::ACTION_DELETE], true)
            || !in_array($behavior, self::BEHAVIORS, true)
        ) {
            return false;
        }
        $this->action = $action;
        $this->scope = [
            'type' => (string) ($options[self::OPTION_SCOPE] ?? MissingScope::ALL) ?: MissingScope::ALL,
            'website' => (string) ($options[self::OPTION_WEBSITE] ?? ''),
            'attribute' => (string) ($options[self::OPTION_ATTRIBUTE] ?? ''),
            'value' => (string) ($options[self::OPTION_VALUE] ?? ''),
        ];
        $this->missingScope->check($this->scope);
        $this->named = [];

        return true;
    }

    /**
     * @inheritDoc
     */
    public function observe(array $records): void
    {
        foreach ($records as $record) {
            $this->named[mb_strtolower($record->identifier)] = true;
        }
    }

    /**
     * @inheritDoc
     */
    public function observeIdentifiers(array $identifiers): void
    {
        foreach ($identifiers as $identifier) {
            $this->named[mb_strtolower($identifier)] = true;
        }
    }

    /**
     * @inheritDoc
     */
    public function label(): string
    {
        return (string) __('Products missing from the file');
    }

    /**
     * @inheritDoc
     */
    public function plan(): ?PostImportPlan
    {
        $only = match ($this->action) {
            self::ACTION_DISABLE => 'enabled',
            self::ACTION_OUT_OF_STOCK => 'in_stock',
            default => '',
        };
        $products = $this->missingScope->products($this->scope, $only);
        $missing = array_values(array_filter(
            $products['skus'],
            fn (string $sku): bool => !isset($this->named[mb_strtolower($sku)])
        ));
        sort($missing, SORT_NATURAL | SORT_FLAG_CASE);
        if ($missing === []) {
            return null;
        }

        [$verb, $word] = match ($this->action) {
            self::ACTION_DISABLE => ['disable', 'DISABLE'],
            self::ACTION_OUT_OF_STOCK => ['set out of stock', 'OUT OF STOCK'],
            default => ['delete', 'DELETE'],
        };

        return new PostImportPlan($verb, $word, $missing, $products['total']);
    }

    /**
     * @inheritDoc
     */
    public function execute(PostImportPlan $plan, bool $dryRun): PostImportOutcome
    {
        $delete = $this->action === self::ACTION_DELETE;
        $columns = match ($this->action) {
            self::ACTION_DISABLE => ['sku', 'product_online'],
            self::ACTION_OUT_OF_STOCK => ['sku', 'qty', 'is_in_stock'],
            default => ['sku'],
        };
        $values = match ($this->action) {
            self::ACTION_DISABLE => ['product_online' => '2'],
            self::ACTION_OUT_OF_STOCK => ['qty' => '0', 'is_in_stock' => '0'],
            default => [],
        };
        $skus = $plan->identifiers;
        $result = $this->importers->get('catalog_product')->run(
            new ImportRequest(
                'catalog_product',
                $delete ? Import::BEHAVIOR_DELETE : Import::BEHAVIOR_APPEND,
                $dryRun,
                true,
                count($skus),
                historyLabel: 'openimport-missing-products.csv'
            ),
            new MappedSource($columns, static function () use ($skus, $values): \Generator {
                foreach ($skus as $sku) {
                    yield ['sku' => $sku] + $values;
                }
            })
        );
        $names = implode(', ', array_slice($skus, 0, self::NAMED))
            . (count($skus) > self::NAMED ? ' ' . __('and %1 more', count($skus) - self::NAMED) : '');
        if (!$result->valid || (!$dryRun && !$result->imported)) {
            $first = $result->errors[0]->message ?? '';

            return new PostImportOutcome(
                (string) __('%1: Magento refused the change. %2', $this->label(), $first),
                0,
                0,
                false
            );
        }
        $count = count($skus);
        $message = (string) match (true) {
            $dryRun && $this->action === self::ACTION_DISABLE
                => __('%1: would disable %2 (%3).', $this->label(), $count, $names),
            $dryRun && $this->action === self::ACTION_OUT_OF_STOCK
                => __('%1: would set %2 out of stock (%3).', $this->label(), $count, $names),
            $dryRun => __('%1: would delete %2 (%3).', $this->label(), $count, $names),
            $this->action === self::ACTION_DISABLE => __('%1: disabled %2 (%3).', $this->label(), $count, $names),
            $this->action === self::ACTION_OUT_OF_STOCK
                => __('%1: set %2 out of stock (%3).', $this->label(), $count, $names),
            default => __('%1: deleted %2 (%3).', $this->label(), $count, $names),
        };

        return new PostImportOutcome($message, $delete ? 0 : $count, $delete ? $count : 0);
    }
}
