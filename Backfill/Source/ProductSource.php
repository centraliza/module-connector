<?php

declare(strict_types=1);

namespace Centraliza\Connector\Backfill\Source;

use Magento\Framework\DB\Select;

/**
 * Products assigned to the website, by SKU. Serves the products and the prices streams.
 */
class ProductSource extends IdPagedSource
{
    protected function select(int $websiteId): Select
    {
        return $this->connection()->select()
            ->from(['e' => $this->table('catalog_product_entity')], ['page_id' => 'e.entity_id', 'sku'])
            ->join(
                ['w' => $this->table('catalog_product_website')],
                $this->connection()->quoteInto('w.product_id = e.entity_id AND w.website_id = ?', $websiteId),
                []
            );
    }

    protected function idColumn(): string
    {
        return 'e.entity_id';
    }

    protected function key(array $row): array
    {
        return ['sku' => (string)$row['sku']];
    }
}
