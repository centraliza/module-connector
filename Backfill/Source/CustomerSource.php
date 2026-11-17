<?php

declare(strict_types=1);

namespace Centraliza\Connector\Backfill\Source;

use Magento\Framework\DB\Select;

/**
 * Customer accounts of the website.
 */
class CustomerSource extends IdPagedSource
{
    protected function select(int $websiteId): Select
    {
        return $this->connection()->select()
            ->from(['c' => $this->table('customer_entity')], ['page_id' => 'c.entity_id', 'email'])
            ->where('c.website_id = ?', $websiteId);
    }

    protected function idColumn(): string
    {
        return 'c.entity_id';
    }

    protected function key(array $row): array
    {
        return ['customer_id' => (int)$row['page_id'], 'email' => (string)$row['email']];
    }
}
