<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity;

use Centraliza\Connector\Core\Port\SettingsInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Only records that belong to a website with a reporting store view are reported.
 */
class WebsiteScope
{
    public function __construct(
        private readonly SettingsInterface $settings,
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * The website a store view belongs to, or null when the store view no longer exists.
     */
    public function websiteOfStore(int $storeId): ?int
    {
        try {
            return (int)$this->storeManager->getStore($storeId)->getWebsiteId();
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    /**
     * For records that belong to a store view, such as orders and carts.
     */
    public function coversStore(int $storeId): bool
    {
        $websiteId = $this->websiteOfStore($storeId);

        return $websiteId !== null && $this->coversAny([$websiteId]);
    }

    /**
     * @param array<int|string> $websiteIds
     */
    public function coversAny(array $websiteIds): bool
    {
        return array_intersect(array_map('intval', $websiteIds), $this->settings->enabledWebsiteIds()) !== [];
    }

    /**
     * @param int[] $productIds
     * @return int[] the ones assigned to a reporting website
     */
    public function coveredProductIds(array $productIds): array
    {
        $enabled = $this->settings->enabledWebsiteIds();
        if ($productIds === [] || $enabled === []) {
            return [];
        }
        $connection = $this->resource->getConnection();

        return array_map('intval', $connection->fetchCol(
            $connection->select()
                ->distinct()
                ->from($this->resource->getTableName('catalog_product_website'), ['product_id'])
                ->where('product_id IN (?)', $productIds)
                ->where('website_id IN (?)', $enabled)
        ));
    }

    /**
     * A SKU with no product behind it is not covered: when a product is deleted,
     * its stock is reported from the product delete, which knows its websites.
     *
     * @param string[] $skus
     * @return array<string, true> covered SKUs as keys
     */
    public function coveredSkus(array $skus): array
    {
        $enabled = $this->settings->enabledWebsiteIds();
        if ($skus === [] || $enabled === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['e' => $this->resource->getTableName('catalog_product_entity')], ['sku'])
                ->joinLeft(
                    ['w' => $this->resource->getTableName('catalog_product_website')],
                    'w.product_id = e.entity_id',
                    ['website_id']
                )
                ->where('e.sku IN (?)', $skus)
        );

        $covered = [];
        foreach ($rows as $row) {
            if (in_array((int)$row['website_id'], $enabled, true)) {
                $covered[(string)$row['sku']] = true;
            }
        }

        return $covered;
    }
}
