<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Config\Backend\OrderNotification;

use Centraliza\Connector\Messaging\Model\Config\Backend\IntegerRangeValue;
use Centraliza\Connector\Messaging\Model\OrderNotification\Config;

class MaxAttempts extends IntegerRangeValue
{
    /**
     * @return int
     */
    protected function getMinimum(): int
    {
        return Config::ATTEMPT_LIMIT_MIN;
    }

    /**
     * @return int|null
     */
    protected function getMaximum(): ?int
    {
        // A limit outside the range either never sends (0) or multiplies possible duplicate messages to the customer.
        return Config::ATTEMPT_LIMIT_MAX;
    }
}
