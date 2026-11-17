<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Persistence\ResourceModel;

use Centraliza\Connector\Infra\Persistence\DeliveryLog as DeliveryLogWriter;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Backs the "Sent requests" grid; the rows are written by Infra\Persistence\DeliveryLog.
 */
class DeliveryLog extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init(DeliveryLogWriter::TABLE, 'log_id');
    }
}
