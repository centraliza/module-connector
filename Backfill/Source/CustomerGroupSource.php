<?php

declare(strict_types=1);

namespace Centraliza\Connector\Backfill\Source;

use Magento\Framework\DB\Select;

/**
 * Every customer group: groups are global.
 */
class CustomerGroupSource extends IdPagedSource
{
    protected function select(int $websiteId): Select
    {
        return $this->connection()->select()
            ->from(['g' => $this->table('customer_group')], ['page_id' => 'g.customer_group_id', 'code' => 'g.customer_group_code']);
    }

    protected function idColumn(): string
    {
        return 'g.customer_group_id';
    }

    protected function key(array $row): array
    {
        return ['group_id' => (int)$row['page_id'], 'code' => (string)$row['code']];
    }
}
