<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\ResourceModel\AbandonedCart;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

class CartActivity
{
    public const TABLE = 'centraliza_sales_cart_activity';

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Record that the customer is looking at the cart now.
     *
     * @param int $quoteId
     * @return void
     */
    public function touch(int $quoteId): void
    {
        // Written with the database's own clock: the abandonment wait compares this against a "now"
        // read from the database, and an app-server clock here would shift the wait by their difference.
        $this->resourceConnection->getConnection()->insertOnDuplicate(
            $this->resourceConnection->getTableName(self::TABLE),
            ['quote_id' => $quoteId, 'last_activity_at' => new Expression('UTC_TIMESTAMP()')],
            ['last_activity_at']
        );
    }
}
