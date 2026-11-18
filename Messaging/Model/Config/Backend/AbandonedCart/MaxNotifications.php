<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Config\Backend\AbandonedCart;

use Centraliza\Connector\Messaging\Model\Config\Backend\IntegerRangeValue;
use Centraliza\Connector\Messaging\Model\AbandonedCart\Config;

class MaxNotifications extends IntegerRangeValue
{
    /**
     * @return int
     */
    protected function getMinimum(): int
    {
        return Config::UNLIMITED_NOTIFICATIONS;
    }

    /**
     * @return int|null
     */
    protected function getMaximum(): ?int
    {
        // No upper bound: the store owner decides how many messages a cart may receive.
        return null;
    }
}
