<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenImport\Model\Import\Category;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Store\Model\Store;

/**
 * The shop's category tree as paths of names ("Default Category/Men/Shoes"), loaded once per run. Paths are matched
 * per level, ignoring case; a slash inside a name is written as "\/" in files.
 */
class CategoryTree
{
    public const SEPARATOR = CategoryPath::SEPARATOR;

    /**
     * @var array<string, int>|null lower-cased path => category id
     */
    private ?array $ids = null;

    /**
     * @var array<int, string> category id => path (default-store names)
     */
    private array $paths = [];

    /**
     * @var array<int, int> category id => parent id
     */
    private array $parents = [];

    /**
     * @param CollectionFactory $collectionFactory
     * @param CategoryPath $pathRules
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly CategoryPath $pathRules
    ) {
    }

    /**
     * @param string $path
     * @return int|null the category id, null when the shop has no such path
     */
    public function id(string $path): ?int
    {
        return $this->ids()[$this->pathRules->key($path)] ?? null;
    }

    /**
     * @param int $id
     * @return string|null
     */
    public function path(int $id): ?string
    {
        $this->ids();

        return $this->paths[$id] ?? null;
    }

    /**
     * @param int $id
     * @return int|null
     */
    public function parentId(int $id): ?int
    {
        $this->ids();

        return $this->parents[$id] ?? null;
    }

    /**
     * Every category id with its path, deepest last.
     *
     * @return array<int, string>
     */
    public function all(): array
    {
        $this->ids();

        return $this->paths;
    }

    /**
     * A category created during the run joins the tree.
     *
     * @param int $id
     * @param int $parentId
     * @param string $name
     * @return string the new path
     */
    public function add(int $id, int $parentId, string $name): string
    {
        $this->ids();
        $parentPath = $this->paths[$parentId] ?? '';
        $path = $parentPath === '' ? $this->pathRules->join([$name]) : $parentPath . self::SEPARATOR . $this->pathRules->join([$name]);
        $this->paths[$id] = $path;
        $this->parents[$id] = $parentId;
        $this->ids[mb_strtolower($path)] = $id;

        return $path;
    }

    /**
     * A category deleted during the run (with its children) leaves the tree.
     *
     * @param int $id
     * @return void
     */
    public function remove(int $id): void
    {
        $this->ids();
        $prefix = ($this->paths[$id] ?? '') . self::SEPARATOR;
        foreach ($this->paths as $otherId => $path) {
            if ($otherId === $id || str_starts_with($path, $prefix)) {
                unset($this->ids[mb_strtolower($path)], $this->paths[$otherId], $this->parents[$otherId]);
            }
        }
    }

    /**
     * Forgets the loaded tree (tests, long processes).
     *
     * @return void
     */
    public function reset(): void
    {
        $this->ids = null;
        $this->paths = [];
        $this->parents = [];
    }

    /**
     * @return array<string, int>
     */
    private function ids(): array
    {
        if ($this->ids !== null) {
            return $this->ids;
        }
        $collection = $this->collectionFactory->create();
        $collection->setStoreId(Store::DEFAULT_STORE_ID)
            ->addAttributeToSelect('name')
            ->addFieldToFilter('level', ['gt' => 0])
            ->setOrder('level', 'ASC')
            ->setOrder('position', 'ASC');
        $names = [];
        $this->ids = [];
        foreach ($collection as $category) {
            $id = (int) $category->getId();
            $parentId = (int) $category->getParentId();
            $name = (string) $category->getName();
            $names[$id] = $name;
            $this->parents[$id] = $parentId;
            $parentPath = $this->paths[$parentId] ?? '';
            $path = $parentPath === '' ? $this->pathRules->join([$name]) : $parentPath . self::SEPARATOR . $this->pathRules->join([$name]);
            $this->paths[$id] = $path;
            $this->ids[mb_strtolower($path)] ??= $id;
        }

        return $this->ids;
    }
}
