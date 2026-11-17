<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Order;

use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Core\Port\ChangeRecorderInterface;
use Centraliza\Connector\Entity\WebsiteScope;
use Magento\Framework\App\ResourceConnection;

/**
 * Reports an order when its store view belongs to a reporting website.
 */
class OrderRecorder
{
    public function __construct(
        private readonly ChangeRecorderInterface $recorder,
        private readonly WebsiteScope $scope,
        private readonly ResourceConnection $resource
    ) {
    }

    public function record(EventVerb $verb, int $orderId, string $incrementId, int $storeId): void
    {
        if ($orderId > 0 && $this->scope->coversStore($storeId)) {
            $this->recorder->record('orders', $verb, ['order_id' => $orderId, 'increment_id' => $incrementId]);
        }
    }

    /**
     * For a change to something that belongs to the order: an invoice, a shipment, a comment.
     */
    public function recordUpdatedById(int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }
        $connection = $this->resource->getConnection('sales');
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName('sales_order', 'sales'), ['increment_id', 'store_id'])
                ->where('entity_id = ?', $orderId)
        );
        if ($row) {
            $this->record(EventVerb::Updated, $orderId, (string)$row['increment_id'], (int)$row['store_id']);
        }
    }
}
