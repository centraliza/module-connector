<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api;

use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;

interface NotificationDeliveryManagementInterface
{
    /**
     * Record whether Centraliza dispatched the notification it accepted under this id.
     *
     * @param string $id the id Centraliza returned in data.id when the notification was sent
     * @param bool $sent
     * @return bool
     * @throws AuthorizationException
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function markSent(string $id, bool $sent): bool;
}
