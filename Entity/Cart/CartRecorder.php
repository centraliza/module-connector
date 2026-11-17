<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Cart;

use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Core\Port\ChangeRecorderInterface;
use Centraliza\Connector\Core\Port\SettingsInterface;
use Centraliza\Connector\Entity\WebsiteScope;
use Magento\Framework\App\ResourceConnection;

/**
 * Reports a cart when its store view belongs to a reporting website and,
 * unless guest carts are reported there, once it belongs to a customer.
 */
class CartRecorder
{
    public function __construct(
        private readonly ChangeRecorderInterface $recorder,
        private readonly WebsiteScope $scope,
        private readonly SettingsInterface $settings,
        private readonly ResourceConnection $resource
    ) {
    }

    public function isReported(int $storeId, int $customerId): bool
    {
        $websiteId = $this->scope->websiteOfStore($storeId);

        return $websiteId !== null
            && $this->scope->coversAny([$websiteId])
            && ($customerId > 0 || $this->settings->reportsGuestCarts($websiteId));
    }

    public function record(EventVerb $verb, int $cartId, int $storeId, int $customerId): void
    {
        if ($cartId > 0 && $this->isReported($storeId, $customerId)) {
            $this->recorder->record('carts', $verb, ['cart_id' => $cartId]);
        }
    }

    /**
     * For a change to an item or an address of the cart.
     */
    public function recordUpdatedById(int $cartId): void
    {
        if ($cartId <= 0) {
            return;
        }
        $connection = $this->resource->getConnection('checkout');
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName('quote', 'checkout'), ['store_id', 'customer_id'])
                ->where('entity_id = ?', $cartId)
        );
        if ($row) {
            $this->record(EventVerb::Updated, $cartId, (int)$row['store_id'], (int)$row['customer_id']);
        }
    }
}
