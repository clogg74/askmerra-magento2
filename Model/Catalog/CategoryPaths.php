<?php

declare(strict_types=1);

namespace AskMerra\Connector\Model\Catalog;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Category paths as shoppers see them in a store view ("Face > Serums"), and the "exclude
 * categories" setting. The category tree is read once per store view and kept for the run.
 */
class CategoryPaths
{
    /** AskMerra keeps at most 50 categories of up to 500 characters per product. */
    private const MAX_CATEGORIES = 50;

    private const MAX_LENGTH = 500;

    /** @var array<int, array<int, array{name: string, path: int[], active: bool, level: int}>> */
    private array $trees = [];

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly TextCleaner $text
    ) {
    }

    /**
     * @param int[] $categoryIds
     * @return string[] most specific first, without the root categories
     */
    public function getPaths(array $categoryIds, int $storeId): array
    {
        $tree = $this->getTree($storeId);
        $rootId = (int) $this->storeManager->getStore($storeId)->getRootCategoryId();
        $paths = [];

        foreach ($categoryIds as $categoryId) {
            $node = $tree[(int) $categoryId] ?? null;

            // Only categories of this store view's tree, active all the way up.
            if ($node === null || !in_array($rootId, $node['path'], true)) {
                continue;
            }

            $names = [];
            foreach ($node['path'] as $id) {
                $ancestor = $tree[$id] ?? null;

                if ($ancestor === null || $ancestor['level'] < 2) {
                    continue;
                }

                if (!$ancestor['active']) {
                    $names = [];
                    break;
                }

                // "|" and ";" separate categories in AskMerra's feed import.
                $names[] = str_replace(['|', ';'], '/', $ancestor['name']);
            }

            if ($names) {
                $paths[implode(' > ', $names)] = count($names);
            }
        }

        arsort($paths);

        return array_map(
            fn (string $path) => $this->text->limit($path, self::MAX_LENGTH),
            array_slice(array_keys($paths), 0, self::MAX_CATEGORIES)
        );
    }

    /**
     * Whether a product is in an excluded category or below one.
     *
     * @param int[] $categoryIds
     * @param int[] $excludedIds
     */
    public function isExcluded(array $categoryIds, array $excludedIds, int $storeId): bool
    {
        if (!$excludedIds || !$categoryIds) {
            return false;
        }

        $tree = $this->getTree($storeId);

        foreach ($categoryIds as $categoryId) {
            $path = $tree[(int) $categoryId]['path'] ?? [(int) $categoryId];

            if (array_intersect($path, $excludedIds)) {
                return true;
            }
        }

        return false;
    }

    /** Forgets the trees, e.g. between full syncs in a long-running process. */
    public function reset(): void
    {
        $this->trees = [];
    }

    private function getTree(int $storeId): array
    {
        if (!isset($this->trees[$storeId])) {
            $tree = [];
            $collection = $this->collectionFactory->create()
                ->setStoreId($storeId)
                ->addAttributeToSelect(['name', 'is_active']);

            foreach ($collection as $category) {
                $tree[(int) $category->getId()] = [
                    'name' => (string) $this->text->toLine((string) $category->getName(), self::MAX_LENGTH),
                    'path' => array_map('intval', explode('/', (string) $category->getPath())),
                    'active' => (bool) $category->getIsActive(),
                    'level' => (int) $category->getLevel(),
                ];
            }

            $this->trees[$storeId] = $tree;
        }

        return $this->trees[$storeId];
    }
}
