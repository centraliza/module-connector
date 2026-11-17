<?php

declare(strict_types=1);

namespace Centraliza\Connector\Backfill\Source;

use Magento\Framework\DB\Select;

/**
 * Orders placed in the store views of the website.
 */
class OrderSource extends IdPagedSource
{
    protected function select(int $websiteId): Select
    {
        return $this->connection()->select()
            ->from(['o' => $this->table('sales_order')], ['page_id' => 'o.entity_id', 'increment_id'])
            ->where('o.store_id IN (?)', $this->storeIds($websiteId) ?: [-1]);
    }

    protected function idColumn(): string
    {
        return 'o.entity_id';
    }

    protected function key(array $row): array
    {
        return ['order_id' => (int)$row['page_id'], 'increment_id' => (string)$row['increment_id']];
    }
}
