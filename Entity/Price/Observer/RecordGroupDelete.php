<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Price\Observer;

use Centraliza\Connector\Entity\Product\ProductSkus;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deleting a customer group removes its tier prices by foreign key cascade,
 * with no event for the products. They are only known before the delete; the
 * events wait for the commit and are dropped if the delete is rolled back.
 */
class RecordGroupDelete implements ObserverInterface
{
    public function __construct(
        private readonly ProductSkus $productSkus,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $group = $observer->getEvent()->getData('object');
            $groupId = is_object($group) && method_exists($group, 'getId') ? $group->getId() : null;
            if ($groupId === null || $groupId === '') {
                return;
            }
            $connection = $this->resource->getConnection();
            $productIds = $connection->fetchCol(
                $connection->select()
                    ->distinct()
                    ->from($this->resource->getTableName('catalog_product_entity_tier_price'), ['entity_id'])
                    ->where('all_groups = 0')
                    ->where('customer_group_id = ?', (int)$groupId)
            );
            $this->productSkus->recordUpdated($productIds, true, 'prices');
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not record prices of a deleted group.', ['exception' => $e]);
        }
    }
}
