<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\ResourceModel\OrderNotification\Notification;

use Centraliza\Connector\Messaging\Model\OrderNotification\Notification;
use Centraliza\Connector\Messaging\Model\ResourceModel\OrderNotification\Notification as NotificationResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(Notification::class, NotificationResource::class);
    }
}
