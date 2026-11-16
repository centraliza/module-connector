<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Db;

use Centraliza\Connector\Core\Port\TransactionStateInterface;
use Magento\Framework\App\ResourceConnection;

class TransactionState implements TransactionStateInterface
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function inTransaction(): bool
    {
        return $this->resource->getConnection()->getTransactionLevel() > 0;
    }
}
