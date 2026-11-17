<?php

declare(strict_types=1);

namespace Centraliza\Connector\Backfill\Source;

use Centraliza\Connector\Infra\Config\Settings;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;

/**
 * Open carts of the website's store views: of customers only, unless the website reports guest carts, as the
 * capture does (Entity\Cart\CartRecorder::isReported). Carts that became orders are left out.
 */
class CartSource extends IdPagedSource
{
    public function __construct(
        ResourceConnection $resource,
        private readonly Settings $settings
    ) {
        parent::__construct($resource);
    }

    protected function select(int $websiteId): Select
    {
        $select = $this->connection()->select()
            ->from(['q' => $this->table('quote')], ['page_id' => 'q.entity_id'])
            ->where('q.store_id IN (?)', $this->storeIds($websiteId) ?: [-1])
            ->where('q.is_active = ?', 1);
        if (!$this->settings->reportsGuestCarts($websiteId)) {
            $select->where('q.customer_id > ?', 0);
        }

        return $select;
    }

    protected function idColumn(): string
    {
        return 'q.entity_id';
    }

    protected function key(array $row): array
    {
        return ['cart_id' => (int)$row['page_id']];
    }
}
