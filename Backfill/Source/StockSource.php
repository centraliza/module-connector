<?php

declare(strict_types=1);

namespace Centraliza\Connector\Backfill\Source;

use Magento\Framework\DB\Select;

/**
 * Source items of the products assigned to the website, by SKU and source.
 */
class StockSource extends IdPagedSource
{
    protected function select(int $websiteId): Select
    {
        return $this->connection()->select()
            ->from(['si' => $this->table('inventory_source_item')], ['page_id' => 'si.source_item_id', 'sku', 'source_code'])
            ->join(['e' => $this->table('catalog_product_entity')], 'e.sku = si.sku', [])
            ->join(
                ['w' => $this->table('catalog_product_website')],
                $this->connection()->quoteInto('w.product_id = e.entity_id AND w.website_id = ?', $websiteId),
                []
            );
    }

    protected function idColumn(): string
    {
        return 'si.source_item_id';
    }

    protected function key(array $row): array
    {
        return ['sku' => (string)$row['sku'], 'source_code' => (string)$row['source_code']];
    }
}
