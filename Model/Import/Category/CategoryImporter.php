<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Category;

use Iranimij\OpenImport\Model\Bridge\ImporterInterface;
use Iranimij\OpenImport\Model\Bridge\ImportRequest;
use Iranimij\OpenImport\Model\Bridge\ImportResult;
use Iranimij\OpenImport\Model\Bridge\RowError;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Framework\Exception\LocalizedException;
use Magento\ImportExport\Model\Import;
use Magento\ImportExport\Model\Import\AbstractSource;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Categories by path ("Default Category/Men/Shoes"). Add/Update creates missing categories (parents on the way too)
 * and updates the given columns; a row with store_view_code sets that store view's values. Delete removes the
 * category with its children. Replace is refused: Magento would have to delete and recreate ids.
 *
 * Writes go through Magento's category model and repository, never raw SQL.
 */
class CategoryImporter implements ImporterInterface
{
    public const COLUMNS = ['path', 'name', 'url_key', 'is_active', 'include_in_menu', 'is_anchor', 'position',
        'description', 'meta_title', 'meta_keywords', 'meta_description', 'display_mode', 'custom_design',
        'page_layout', 'store_view_code'];

    private const FLAGS = ['is_active', 'include_in_menu', 'is_anchor'];

    private const TEXTS = ['name', 'url_key', 'description', 'meta_title', 'meta_keywords', 'meta_description',
        'custom_design', 'page_layout'];

    private const DISPLAY_MODES = ['PRODUCTS', 'PAGE', 'PRODUCTS_AND_PAGE'];

    /**
     * @param CategoryTree $tree
     * @param CategoryPath $paths
     * @param CategoryRepositoryInterface $categories
     * @param CategoryFactory $categoryFactory
     * @param CategoryResource $categoryResource
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CategoryTree $tree,
        private readonly CategoryPath $paths,
        private readonly CategoryRepositoryInterface $categories,
        private readonly CategoryFactory $categoryFactory,
        private readonly CategoryResource $categoryResource,
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
        $this->tree->reset();
        $errors = [];
        $rows = [];
        $position = 0;
        if ($request->behavior === Import::BEHAVIOR_REPLACE) {
            $errors[] = new RowError(null, 'behavior', 'Categories cannot be imported with "Replace": Magento would delete and recreate them with new ids. Use Add/Update.', null, true);
        }
        $stores = $this->storeCodes();
        foreach ($source as $row) {
            $problem = $this->check($row, $stores, $request->behavior);
            if ($problem !== null) {
                $errors[] = new RowError($position, $problem[0], $problem[1], $problem[0], true);
            } else {
                $rows[$position] = $row;
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
                    $deleted += $this->delete($row);
                } else {
                    [$c, $u] = $this->save($row, $stores);
                    $created += $c;
                    $updated += $u;
                }
            } catch (\Throwable $exception) {
                $this->logger->error('OpenImport category import: ' . $exception->getMessage(), ['exception' => $exception]);
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
     * @return array{0: string, 1: string}|null column and message
     */
    private function check(array $row, array $stores, string $behavior): ?array
    {
        $segments = $this->paths->segments((string) ($row['path'] ?? ''));
        if ($segments === []) {
            return ['path', 'the path is empty (write it as "Default Category/Men/Shoes")'];
        }
        if ($behavior !== Import::BEHAVIOR_DELETE && $this->tree->id($segments[0]) === null) {
            return ['path', sprintf('"%s" is not a root category of this shop; a path starts with the root category\'s name', $segments[0])];
        }
        $store = trim((string) ($row['store_view_code'] ?? ''));
        if ($store !== '' && !isset($stores[$store])) {
            return ['store_view_code', sprintf('there is no store view "%s"', $store)];
        }
        if ($store !== '' && $this->storeManager->hasSingleStore()) {
            // Magento saves every store-view value of a one-store-view shop in the default scope: the default name
            // would change and the next run would no longer find the category by its path.
            return ['store_view_code', 'this shop has only one store view, so Magento keeps every value in the default scope; map the column to the default field (for example "name" instead of a store-view name)'];
        }
        foreach (self::FLAGS as $flag) {
            if (isset($row[$flag]) && trim((string) $row[$flag]) !== '' && $this->flag($row[$flag]) === null) {
                return [$flag, 'use 1 or 0 (also yes/no, true/false)'];
            }
        }
        if (isset($row['position']) && trim((string) $row['position']) !== '' && !is_numeric($row['position'])) {
            return ['position', 'the position must be a whole number'];
        }
        $mode = strtoupper(trim((string) ($row['display_mode'] ?? '')));
        if ($mode !== '' && !in_array($mode, self::DISPLAY_MODES, true)) {
            return ['display_mode', 'use PRODUCTS, PAGE or PRODUCTS_AND_PAGE'];
        }
        $name = trim((string) ($row['name'] ?? ''));
        if ($behavior !== Import::BEHAVIOR_DELETE && $store === '' && $name !== '' && $name !== end($segments)
            && $this->tree->id((string) $row['path']) === null) {
            return ['name', sprintf(
                'a new category is named after the last part of its path ("%s"); use the name in the path, or leave the name column empty',
                (string) end($segments)
            )];
        }
        if ($behavior === Import::BEHAVIOR_DELETE && $this->tree->id((string) $row['path']) === null) {
            return ['path', 'there is no such category'];
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
        $segments = $this->paths->segments((string) $row['path']);
        $created = 0;
        $id = $this->tree->id($segments[0]);
        $parentId = (int) $id;
        $walked = [$segments[0]];
        foreach (array_slice($segments, 1) as $name) {
            $walked[] = $name;
            $id = $this->tree->id($this->paths->join($walked));
            if ($id === null) {
                // Created in the default scope as the admin does (the repository would save in the current store's
                // scope, which in cron and CLI is a store view).
                $parent = $this->categories->get($parentId, Store::DEFAULT_STORE_ID);
                $category = $this->categoryFactory->create();
                $category->setStoreId(Store::DEFAULT_STORE_ID)
                    ->setParentId($parentId)
                    ->setPath((string) $parent->getPath())
                    ->setName($name)
                    ->setIsActive(true)
                    ->setIncludeInMenu(true);
                $category->setAttributeSetId($category->getDefaultAttributeSetId());
                $this->categoryResource->save($category);
                $id = (int) $category->getId();
                $this->tree->add($id, $parentId, $name);
                $created++;
            }
            $parentId = $id;
        }
        $storeCode = trim((string) ($row['store_view_code'] ?? ''));
        $storeId = $storeCode === '' ? Store::DEFAULT_STORE_ID : $stores[$storeCode];
        $values = $this->values($row);
        if ($values === [] && ($created > 0 || $storeCode !== '')) {
            return [$created, 0];
        }
        $category = $this->categories->get((int) $id, $storeId);
        if (!$category instanceof Category) {
            throw new LocalizedException(__('The category "%1" could not be loaded.', $row['path']));
        }
        $changedAttributes = [];
        foreach ($values as $attribute => $value) {
            if ((string) $category->getData($attribute) !== (string) $value) {
                $category->setData($attribute, $value);
                $changedAttributes[] = $attribute;
            }
        }
        $changed = $changedAttributes !== [];
        if ($changed) {
            $category->setStoreId($storeId);
            if ($storeId !== Store::DEFAULT_STORE_ID && !in_array('url_key', $changedAttributes, true)) {
                // A store view's own values (name, description, meta …) are saved one by one: a full save in store
                // scope regenerates every URL rewrite of the category and its children and can clash with itself.
                foreach ($changedAttributes as $attribute) {
                    $this->categoryResource->saveAttribute($category, $attribute);
                }
            } else {
                // The resource model saves the store's values (the repository keeps interface fields only).
                $this->categoryResource->save($category);
            }
            if (isset($values['name']) && $storeId === Store::DEFAULT_STORE_ID) {
                $this->tree->reset();
            }
        }

        return [$created, $changed && $created === 0 ? 1 : 0];
    }

    /**
     * @param array<string, mixed> $row
     * @return int categories deleted (the category and its children)
     */
    private function delete(array $row): int
    {
        $id = $this->tree->id((string) $row['path']);
        if ($id === null) {
            return 0;
        }
        $prefix = $this->paths->key((string) $row['path']) . CategoryTree::SEPARATOR;
        $count = 1;
        foreach ($this->tree->all() as $path) {
            if (str_starts_with(mb_strtolower($path), $prefix)) {
                $count++;
            }
        }
        $this->categories->deleteByIdentifier($id);
        $this->tree->remove($id);

        return $count;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed> attribute => value, only columns the row sets
     */
    private function values(array $row): array
    {
        $values = [];
        foreach (self::TEXTS as $column) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $values[$column] = (string) $row[$column];
            }
        }
        foreach (self::FLAGS as $flag) {
            if (isset($row[$flag]) && trim((string) $row[$flag]) !== '') {
                $values[$flag] = $this->flag($row[$flag]) ? 1 : 0;
            }
        }
        if (isset($row['position']) && trim((string) $row['position']) !== '') {
            $values['position'] = (int) $row['position'];
        }
        $mode = strtoupper(trim((string) ($row['display_mode'] ?? '')));
        if ($mode !== '') {
            $values['display_mode'] = $mode;
        }

        return $values;
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
     * @return array<string, int> store view code => id
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
