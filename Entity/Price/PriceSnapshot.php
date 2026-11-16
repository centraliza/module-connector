<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Price;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;

/**
 * The raw prices of a product as stored: base price, special price with its
 * dates, and tier prices. Catalog price rules are not part of it.
 */
class PriceSnapshot
{
    /** Product fields that are price; a change to any of them is a change to the "prices" record. */
    public const FIELDS = ['price', 'special_price', 'special_from_date', 'special_to_date', 'tier_price'];

    private const ATTRIBUTES = ['price', 'special_price', 'special_from_date', 'special_to_date'];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * @param string[] $skus
     * @return array<string, string> stored prices by SKU, in a form that only serves comparison
     */
    public function forSkus(array $skus): array
    {
        $skus = array_values(array_unique($skus));
        if ($skus === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $skuById = $connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName('catalog_product_entity'), ['entity_id', 'sku'])
                ->where('sku IN (?)', $skus)
        );
        if ($skuById === []) {
            return [];
        }
        $ids = array_keys($skuById);
        $state = array_fill_keys($ids, []);

        foreach (self::ATTRIBUTES as $code) {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
            if (!$attribute->getId()) {
                continue;
            }
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($attribute->getBackendTable(), ['entity_id', 'store_id', 'value'])
                    ->where('attribute_id = ?', (int)$attribute->getId())
                    ->where('entity_id IN (?)', $ids)
                    ->order(['entity_id', 'store_id'])
            );
            foreach ($rows as $row) {
                $state[(int)$row['entity_id']][$code][(int)$row['store_id']] = $row['value'];
            }
        }

        $tiers = $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->resource->getTableName('catalog_product_entity_tier_price'),
                    ['entity_id', 'website_id', 'all_groups', 'customer_group_id', 'qty', 'value', 'percentage_value']
                )
                ->where('entity_id IN (?)', $ids)
                ->order(['entity_id', 'website_id', 'all_groups', 'customer_group_id', 'qty'])
        );
        foreach ($tiers as $row) {
            $id = (int)$row['entity_id'];
            unset($row['entity_id']);
            $state[$id]['tier_price'][] = $row;
        }

        $snapshot = [];
        foreach ($state as $id => $prices) {
            $snapshot[(string)$skuById[$id]] = (string)json_encode($prices);
        }

        return $snapshot;
    }
}
