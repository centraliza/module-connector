<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Product;

use Centraliza\Connector\Entity\AttributeLabels;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Eav\Api\AttributeSetRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Arranges a product for someone who does not have this store's catalog at
 * hand: ids come with the name they stand for, files with their URL, and the
 * fields are grouped by subject. Nothing the store knows is left out; what
 * has no group of its own stays where the REST API puts it.
 */
class ProductRecord
{
    /** REST fields that are placed by name below; any other is kept as it comes. */
    private const PLACED = [
        'id', 'sku', 'name', 'type_id', 'attribute_set_id', 'status', 'visibility', 'weight', 'created_at',
        'updated_at', 'price', 'tier_prices', 'product_links', 'options', 'media_gallery_entries',
        'custom_attributes', 'extension_attributes',
    ];
    private const PRICE_ATTRIBUTES = ['special_price', 'special_from_date', 'special_to_date'];
    private const ENTITY_TYPE = 'catalog_product';

    public function __construct(
        private readonly ProductResource $productResource,
        private readonly AttributeSetRepositoryInterface $attributeSets,
        private readonly CategoryCollectionFactory $categories,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resource,
        private readonly AttributeLabels $labels
    ) {
    }

    /**
     * @param array<string, mixed> $rest the product as the REST API answers it, custom attributes by code
     * @return array<string, mixed>
     */
    public function arrange(array $rest, int $storeId): array
    {
        $extension = (array)($rest['extension_attributes'] ?? []);
        $attributes = (array)($rest['custom_attributes'] ?? []);

        $prices = ['price' => $rest['price'] ?? null];
        foreach (self::PRICE_ATTRIBUTES as $code) {
            $prices[$code] = $attributes[$code] ?? null;
            unset($attributes[$code]);
        }
        $prices['tier_prices'] = $rest['tier_prices'] ?? [];
        // The same links, with names, are under "categories".
        unset($attributes['category_ids']);

        $record = [
            'id' => $rest['id'] ?? null,
            'sku' => $rest['sku'] ?? null,
            'name' => $rest['name'] ?? null,
            'type_id' => $rest['type_id'] ?? null,
            'attribute_set' => $this->attributeSet((int)($rest['attribute_set_id'] ?? 0)),
            'status' => $rest['status'] ?? null,
            'visibility' => $rest['visibility'] ?? null,
            'weight' => $rest['weight'] ?? null,
            'created_at' => $rest['created_at'] ?? null,
            'updated_at' => $rest['updated_at'] ?? null,
            'websites' => $this->websites((array)($extension['website_ids'] ?? [])),
            'categories' => $this->categories((array)($extension['category_links'] ?? []), $storeId),
            'prices' => $prices,
            'stock' => $extension['stock_item'] ?? null,
            'images' => $this->images((array)($rest['media_gallery_entries'] ?? []), $storeId),
        ];
        if (isset($extension['configurable_product_options']) || isset($extension['configurable_product_links'])) {
            $record['variations'] = [
                'attributes' => $this->variationAttributes((array)($extension['configurable_product_options'] ?? []), $storeId),
                'children' => $this->children((array)($extension['configurable_product_links'] ?? [])),
            ];
        }
        $record['links'] = $rest['product_links'] ?? [];
        $record['options'] = $rest['options'] ?? [];
        $record['attributes'] = $this->labels->withLabels(self::ENTITY_TYPE, $attributes, $storeId);
        $record += array_diff_key($rest, array_flip(self::PLACED));

        $remaining = array_diff_key($extension, array_flip([
            'website_ids', 'category_links', 'stock_item', 'configurable_product_options', 'configurable_product_links',
        ]));
        if ($remaining !== []) {
            $record['extension_attributes'] = $remaining;
        }

        return $record;
    }

    /**
     * @return array{id: int, name: string|null}
     */
    private function attributeSet(int $attributeSetId): array
    {
        try {
            $name = (string)$this->attributeSets->get($attributeSetId)->getAttributeSetName();
        } catch (NoSuchEntityException) {
            $name = null;
        }

        return ['id' => $attributeSetId, 'name' => $name];
    }

    /**
     * @param array<int, int|string> $websiteIds
     * @return array<int, array{id: int, code: string|null}>
     */
    private function websites(array $websiteIds): array
    {
        $websites = [];
        foreach ($websiteIds as $websiteId) {
            try {
                $code = (string)$this->storeManager->getWebsite((int)$websiteId)->getCode();
            } catch (NoSuchEntityException) {
                $code = null;
            }
            $websites[] = ['id' => (int)$websiteId, 'code' => $code];
        }

        return $websites;
    }

    /**
     * @param array<int, array<string, mixed>> $links
     * @return array<int, array{id: int, name: string|null, path: string|null, position: int}>
     */
    private function categories(array $links, int $storeId): array
    {
        if ($links === []) {
            return [];
        }
        $ids = array_map(static fn (array $link): int => (int)$link['category_id'], $links);
        $paths = [];
        $collection = $this->categories->create()->addIdFilter($ids);
        foreach ($collection as $category) {
            $paths[(int)$category->getId()] = array_map('intval', explode('/', (string)$category->getPath()));
        }

        $names = [];
        $ancestors = array_unique(array_merge($ids, ...array_values($paths)));
        $named = $this->categories->create()->setStoreId($storeId)->addAttributeToSelect('name')->addIdFilter($ancestors);
        foreach ($named as $category) {
            $names[(int)$category->getId()] = (string)$category->getName();
        }

        $categories = [];
        foreach ($links as $link) {
            $id = (int)$link['category_id'];
            // The first two levels are the tree root and the store's root category.
            $trail = array_filter(array_map(
                static fn (int $ancestor): ?string => $names[$ancestor] ?? null,
                array_slice($paths[$id] ?? [], 2)
            ));
            $categories[] = [
                'id' => $id,
                'name' => $names[$id] ?? null,
                'path' => $trail === [] ? null : implode(' / ', $trail),
                'position' => (int)($link['position'] ?? 0),
            ];
        }

        return $categories;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function images(array $entries, int $storeId): array
    {
        $base = null;
        try {
            $store = $this->storeManager->getStore($storeId);
            if ($store instanceof Store) {
                $base = rtrim((string)$store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/') . '/catalog/product';
            }
        } catch (NoSuchEntityException) {
            $base = null;
        }
        foreach ($entries as &$entry) {
            $file = (string)($entry['file'] ?? '');
            $entry['url'] = $base !== null && $file !== '' ? $base . '/' . ltrim($file, '/') : null;
        }

        return $entries;
    }

    /**
     * @param array<int, array<string, mixed>> $options
     * @return array<int, array<string, mixed>>
     */
    private function variationAttributes(array $options, int $storeId): array
    {
        foreach ($options as &$option) {
            $attributeId = (int)($option['attribute_id'] ?? 0);
            $attribute = $this->productResource->getAttribute($attributeId);
            $option['attribute_code'] = $attribute ? (string)$attribute->getAttributeCode() : null;
            $values = [];
            foreach ((array)($option['values'] ?? []) as $value) {
                $id = $value['value_index'] ?? null;
                $values[] = [
                    'value' => $id,
                    'label' => $this->labels->optionLabel(self::ENTITY_TYPE, $attributeId, $id, $storeId),
                ];
            }
            $option['values'] = $values;
        }

        return $options;
    }

    /**
     * @param array<int, int|string> $productIds
     * @return array<int, array{id: int, sku: string|null}>
     */
    private function children(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $skus = $connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName('catalog_product_entity'), ['entity_id', 'sku'])
                ->where('entity_id IN (?)', array_map('intval', $productIds))
        );

        return array_map(
            static fn (int|string $id): array => ['id' => (int)$id, 'sku' => isset($skus[$id]) ? (string)$skus[$id] : null],
            array_values($productIds)
        );
    }
}
