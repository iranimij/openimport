<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Attribute;

use Iranimij\OpenImport\Model\Bridge\ImporterInterface;
use Iranimij\OpenImport\Model\Bridge\ImportRequest;
use Iranimij\OpenImport\Model\Bridge\ImportResult;
use Iranimij\OpenImport\Model\Bridge\RowError;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Api\Data\ProductAttributeInterfaceFactory;
use Magento\Catalog\Api\ProductAttributeManagementInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Eav\Api\Data\AttributeFrontendLabelInterfaceFactory;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\AbstractSource;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Product attributes by code: Add/Update creates missing attributes (input type, label, options, flags, scope) and
 * assigns them to attribute sets and groups; a row with store_view_code sets that store view's label and option
 * labels (same order as the default options). Delete removes the attribute from the given attribute sets only;
 * OpenImport never deletes an attribute (its values on products would go with it). Replace is refused.
 *
 * Writes go through Magento's attribute repository and attribute management, never raw SQL.
 */
class AttributeImporter implements ImporterInterface
{
    public const COLUMNS = ['attribute_code', 'label', 'frontend_input', 'options', 'is_required', 'is_unique',
        'default_value', 'scope', 'is_searchable', 'is_filterable', 'is_filterable_in_search', 'is_comparable',
        'is_visible_on_front', 'used_in_product_listing', 'is_html_allowed_on_front', 'is_used_for_promo_rules',
        'used_for_sort_by', 'attribute_set', 'attribute_group', 'store_view_code'];

    public const OPTION_SEPARATOR = '|';

    private const INPUTS = ['text', 'textarea', 'texteditor', 'select', 'multiselect', 'boolean', 'date', 'datetime',
        'price', 'weight'];

    private const FLAGS = ['is_required', 'is_unique', 'is_searchable', 'is_filterable_in_search', 'is_comparable',
        'is_visible_on_front', 'used_in_product_listing', 'is_html_allowed_on_front', 'is_used_for_promo_rules',
        'used_for_sort_by'];

    private const SCOPES = ['global', 'website', 'store'];

    /**
     * @param ProductAttributeRepositoryInterface $attributes
     * @param ProductAttributeInterfaceFactory $attributeFactory
     * @param AttributeFrontendLabelInterfaceFactory $frontendLabelFactory
     * @param ProductAttributeManagementInterface $management
     * @param AttributeSets $sets
     * @param AttributeOptions $options
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ProductAttributeRepositoryInterface $attributes,
        private readonly ProductAttributeInterfaceFactory $attributeFactory,
        private readonly AttributeFrontendLabelInterfaceFactory $frontendLabelFactory,
        private readonly ProductAttributeManagementInterface $management,
        private readonly AttributeSets $sets,
        private readonly AttributeOptions $options,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function run(ImportRequest $request, AbstractSource $source): ImportResult
    {
        $started = microtime(true);
        $errors = [];
        $rows = [];
        $position = 0;
        if ($request->behavior === Import::BEHAVIOR_REPLACE) {
            $errors[] = new RowError(null, 'behavior', 'Attributes cannot be imported with "Replace". Use Add/Update, or Delete to take attributes out of attribute sets.', null, true);
        }
        $stores = $this->storeCodes();
        $seen = [];
        foreach ($source as $row) {
            $problem = $this->check($row, $stores, $request->behavior, $seen);
            if ($problem !== null) {
                $errors[] = new RowError($position, $problem[0], $problem[1], $problem[0], true);
            } else {
                $rows[$position] = $row;
                if (trim((string) ($row['store_view_code'] ?? '')) === '') {
                    $seen[trim((string) $row['attribute_code'])] = true;
                }
            }
            $position++;
        }
        $invalid = count(array_filter($errors, static fn (RowError $error): bool => $error->row !== null));
        $valid = $errors === [] || ($request->skipErrors && $invalid <= $request->allowedErrors
            && !array_filter($errors, static fn (RowError $error): bool => $error->row === null));
        if ($request->dryRun || !$valid) {
            return $this->result($valid, false, $position, $invalid, 0, 0, 0, $errors, $started);
        }
        $created = $updated = $deleted = 0;
        foreach ($rows as $index => $row) {
            try {
                if ($request->behavior === Import::BEHAVIOR_DELETE) {
                    $deleted += $this->unassign($row);
                } else {
                    [$c, $u] = $this->save($row, $stores);
                    $created += $c;
                    $updated += $u;
                }
            } catch (\Throwable $exception) {
                $this->logger->error('OpenImport attribute import: ' . $exception->getMessage(), ['exception' => $exception]);
                $errors[] = new RowError($index, 'save', $exception->getMessage(), null, !$request->skipErrors);
                $invalid++;
                if (!$request->skipErrors) {
                    return $this->result(false, $created + $updated + $deleted > 0, $position, $invalid, $created, $updated, $deleted, $errors, $started);
                }
            }
        }

        return $this->result(true, true, $position, $invalid, $created, $updated, $deleted, $errors, $started);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int> $stores
     * @param string $behavior
     * @param array<string, true> $seen codes of default rows earlier in the file (created before their store rows)
     * @return array{0: string, 1: string}|null
     */
    private function check(array $row, array $stores, string $behavior, array $seen): ?array
    {
        $code = trim((string) ($row['attribute_code'] ?? ''));
        if ($code === '') {
            return ['attribute_code', 'the attribute code is empty'];
        }
        if (preg_match('/^[a-z][a-z0-9_]{0,59}$/', $code) !== 1) {
            return ['attribute_code', 'use lower-case letters, digits and underscores, starting with a letter (at most 60 characters)'];
        }
        $store = trim((string) ($row['store_view_code'] ?? ''));
        if ($store !== '' && !isset($stores[$store])) {
            return ['store_view_code', sprintf('there is no store view "%s"', $store)];
        }
        $existing = $this->find($code);
        $input = strtolower(trim((string) ($row['frontend_input'] ?? '')));
        if ($behavior !== Import::BEHAVIOR_DELETE && $existing === null && $store === '') {
            if (trim((string) ($row['label'] ?? '')) === '') {
                return ['label', 'a new attribute needs a label'];
            }
            if ($input === '') {
                return ['frontend_input', 'a new attribute needs an input type (' . implode(', ', self::INPUTS) . ')'];
            }
        }
        if ($input !== '' && !in_array($input, self::INPUTS, true)) {
            return ['frontend_input', 'use one of ' . implode(', ', self::INPUTS)];
        }
        if ($existing !== null && $input !== '' && $input !== (string) $existing->getFrontendInput()) {
            return ['frontend_input', sprintf('"%s" is a %s attribute; the input type of an existing attribute cannot change', $code, (string) $existing->getFrontendInput())];
        }
        if ($store !== '' && $existing === null && !isset($seen[$code])) {
            return ['store_view_code', 'a store-view row needs the attribute to exist (put the default row first)'];
        }
        foreach (self::FLAGS as $flag) {
            if (isset($row[$flag]) && trim((string) $row[$flag]) !== '' && $this->flag($row[$flag]) === null) {
                return [$flag, 'use 1 or 0 (also yes/no, true/false)'];
            }
        }
        if (isset($row['is_filterable']) && trim((string) $row['is_filterable']) !== '' && !in_array(trim((string) $row['is_filterable']), ['0', '1', '2'], true)) {
            return ['is_filterable', 'use 0 (no), 1 (filterable with results) or 2 (filterable, no results)'];
        }
        $scope = strtolower(trim((string) ($row['scope'] ?? '')));
        if ($scope !== '' && !in_array($scope, self::SCOPES, true)) {
            return ['scope', 'use global, website or store'];
        }
        if ($behavior === Import::BEHAVIOR_DELETE) {
            if ($existing === null) {
                return ['attribute_code', 'there is no such attribute'];
            }
            if (trim((string) ($row['attribute_set'] ?? '')) === '') {
                return ['attribute_set', 'Delete takes an attribute out of the attribute sets listed here; OpenImport never deletes an attribute itself'];
            }
        }
        foreach ($this->sets->names((string) ($row['attribute_set'] ?? '')) as $name) {
            if ($this->sets->id($name) === null) {
                return ['attribute_set', sprintf('there is no attribute set "%s"', $name)];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int> $stores
     * @return array{0: int, 1: int} created, updated
     */
    private function save(array $row, array $stores): array
    {
        $code = trim((string) $row['attribute_code']);
        $store = trim((string) ($row['store_view_code'] ?? ''));
        $existing = $this->find($code);
        if ($store !== '') {
            if ($existing === null) {
                throw new LocalizedException(__('The attribute "%1" does not exist (its default row failed?).', $code));
            }
            return [0, $this->saveStoreLabels($existing, $row, $stores[$store]) ? 1 : 0];
        }
        $created = $existing === null;
        $attribute = $existing ?? $this->attributeFactory->create();
        $changed = false;
        if ($created) {
            $attribute->setAttributeCode($code)
                ->setFrontendInput(strtolower(trim((string) $row['frontend_input'])))
                ->setDefaultFrontendLabel(trim((string) $row['label']))
                ->setScope('global')
                ->setIsRequired(false)
                ->setIsUserDefined(true);
        } elseif (isset($row['label']) && trim((string) $row['label']) !== ''
            && trim((string) $row['label']) !== (string) $attribute->getDefaultFrontendLabel()) {
            $attribute->setDefaultFrontendLabel(trim((string) $row['label']));
            $changed = true;
        }
        $scope = strtolower(trim((string) ($row['scope'] ?? '')));
        if ($scope !== '' && $scope !== (string) $attribute->getScope()) {
            $attribute->setScope($scope);
            $changed = true;
        }
        foreach (self::FLAGS as $flag) {
            if (isset($row[$flag]) && trim((string) $row[$flag]) !== '') {
                $value = $this->flag($row[$flag]) ? '1' : '0';
                if ($attribute instanceof DataObject && (string) (int) $attribute->getData($flag) !== $value) {
                    $attribute->setData($flag, $value);
                    $changed = true;
                }
            }
        }
        if (isset($row['is_filterable']) && trim((string) $row['is_filterable']) !== ''
            && (string) (int) $attribute->getIsFilterable() !== trim((string) $row['is_filterable'])) {
            $attribute->setIsFilterable((bool) (int) trim((string) $row['is_filterable']));
            if ($attribute instanceof DataObject) {
                $attribute->setData('is_filterable', (int) trim((string) $row['is_filterable']));
            }
            $changed = true;
        }
        if (array_key_exists('default_value', $row) && $row['default_value'] !== null
            && (string) $row['default_value'] !== (string) $attribute->getDefaultValue()) {
            $attribute->setDefaultValue((string) $row['default_value']);
            $changed = true;
        }
        if (isset($row['options']) && in_array((string) $attribute->getFrontendInput(), ['select', 'multiselect'], true)) {
            $changed = $this->mergeOptions($attribute, (string) $row['options']) || $changed;
        }
        if ($created || $changed) {
            $attribute = $this->attributes->save($attribute);
        }
        $assigned = $this->assign($attribute, $row);

        return [$created ? 1 : 0, !$created && ($changed || $assigned) ? 1 : 0];
    }

    /**
     * Adds options the attribute does not have yet (by admin label, ignoring case); never removes or reorders, and
     * keeps every store view's labels of the existing options.
     *
     * @param ProductAttributeInterface $attribute
     * @param string $list "Red|Green|Blue"
     * @return bool whether options were added
     */
    private function mergeOptions(ProductAttributeInterface $attribute, string $list): bool
    {
        $wanted = $this->split($list);
        if ($wanted === []) {
            return false;
        }
        $existing = $this->options->load((int) $attribute->getAttributeId());
        $have = [];
        foreach ($existing as $data) {
            $have[mb_strtolower($data['label'])] = true;
        }
        $new = [];
        foreach ($wanted as $label) {
            if (!isset($have[mb_strtolower($label)])) {
                $new[] = $label;
                $have[mb_strtolower($label)] = true;
            }
        }
        if ($new === []) {
            return false;
        }
        $attribute->setOptions($this->options->build($existing, $new));

        return true;
    }

    /**
     * A store-view row: the store label and, in the order of the default options, the store option labels.
     *
     * @param ProductAttributeInterface $attribute
     * @param array<string, mixed> $row
     * @param int $storeId
     * @return bool whether anything changed
     */
    private function saveStoreLabels(ProductAttributeInterface $attribute, array $row, int $storeId): bool
    {
        $changed = false;
        $label = trim((string) ($row['label'] ?? ''));
        if ($label !== '') {
            $labels = [];
            $found = false;
            foreach ($attribute->getFrontendLabels() ?? [] as $frontendLabel) {
                if ((int) $frontendLabel->getStoreId() === $storeId) {
                    $found = true;
                    if ((string) $frontendLabel->getLabel() !== $label) {
                        $frontendLabel->setLabel($label);
                        $changed = true;
                    }
                }
                $labels[] = $frontendLabel;
            }
            if (!$found) {
                $labels[] = $this->frontendLabelFactory->create()->setStoreId($storeId)->setLabel($label);
                $changed = true;
            }
            $attribute->setFrontendLabels($labels);
        }
        $storeOptions = isset($row['options']) ? explode(self::OPTION_SEPARATOR, (string) $row['options']) : [];
        $existing = $this->options->load((int) $attribute->getAttributeId());
        $optionsChanged = false;
        $index = 0;
        foreach ($existing as $id => $data) {
            $storeLabel = trim($storeOptions[$index] ?? '');
            $index++;
            if ($storeLabel !== '' && ($data['stores'][$storeId] ?? '') !== $storeLabel) {
                $existing[$id]['stores'][$storeId] = $storeLabel;
                $optionsChanged = true;
            }
        }
        if ($optionsChanged) {
            $attribute->setOptions($this->options->build($existing));
        } else {
            // Without options the repository leaves the option labels alone.
            $attribute->setOptions(null);
        }
        if ($changed || $optionsChanged) {
            $this->attributes->save($attribute);
        }

        return $changed || $optionsChanged;
    }

    /**
     * @param ProductAttributeInterface $attribute
     * @param array<string, mixed> $row
     * @return bool whether a new assignment was made
     */
    private function assign(ProductAttributeInterface $attribute, array $row): bool
    {
        $assigned = false;
        $group = trim((string) ($row['attribute_group'] ?? ''));
        foreach ($this->sets->names((string) ($row['attribute_set'] ?? '')) as $name) {
            $setId = (int) $this->sets->id($name);
            if ($this->sets->has($setId, (string) $attribute->getAttributeCode())) {
                continue;
            }
            $groupId = $this->sets->groupId($setId, $group === '' ? 'General' : $group);
            $this->management->assign($setId, $groupId, (string) $attribute->getAttributeCode(), 0);
            $this->sets->forget($setId);
            $assigned = true;
        }

        return $assigned;
    }

    /**
     * @param array<string, mixed> $row
     * @return int assignments removed
     */
    private function unassign(array $row): int
    {
        $code = trim((string) $row['attribute_code']);
        $removed = 0;
        foreach ($this->sets->names((string) ($row['attribute_set'] ?? '')) as $name) {
            $setId = (int) $this->sets->id($name);
            if (!$this->sets->has($setId, $code)) {
                continue;
            }
            $this->management->unassign((string) $setId, $code);
            $this->sets->forget($setId);
            $removed++;
        }

        return $removed;
    }

    /**
     * @param string $code
     * @return ProductAttributeInterface|null
     */
    private function find(string $code): ?ProductAttributeInterface
    {
        try {
            return $this->attributes->get($code);
        } catch (NoSuchEntityException $exception) {
            return null;
        }
    }

    /**
     * @param string $list
     * @return string[]
     */
    private function split(string $list): array
    {
        return array_values(array_filter(
            array_map('trim', explode(self::OPTION_SEPARATOR, $list)),
            static fn (string $label): bool => $label !== ''
        ));
    }

    /**
     * @param mixed $value
     * @return bool|null
     */
    private function flag(mixed $value): ?bool
    {
        return match (strtolower(trim((string) $value))) {
            '1', 'yes', 'true', 'ja', 'y', 'on' => true,
            '0', 'no', 'false', 'nein', 'n', 'off' => false,
            default => null,
        };
    }

    /**
     * @return array<string, int>
     */
    private function storeCodes(): array
    {
        $codes = [];
        foreach ($this->storeManager->getStores() as $store) {
            $codes[(string) $store->getCode()] = (int) $store->getId();
        }

        return $codes;
    }

    /**
     * @param bool $valid
     * @param bool $imported
     * @param int $rows
     * @param int $invalid
     * @param int $created
     * @param int $updated
     * @param int $deleted
     * @param RowError[] $errors
     * @param float $started
     * @return ImportResult
     */
    private function result(
        bool $valid,
        bool $imported,
        int $rows,
        int $invalid,
        int $created,
        int $updated,
        int $deleted,
        array $errors,
        float $started
    ): ImportResult {
        return new ImportResult($valid, $imported, $rows, $rows, $invalid, $created, $updated, $deleted, $errors, null, microtime(true) - $started);
    }
}
