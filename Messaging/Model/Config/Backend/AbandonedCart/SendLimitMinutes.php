<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Config\Backend\AbandonedCart;

use Centraliza\Connector\Messaging\Model\Config\Backend\IntegerRangeValue;
use Centraliza\Connector\Messaging\Model\AbandonedCart\Config;

class SendLimitMinutes extends IntegerRangeValue
{
    /**
     * @return int
     */
    protected function getMinimum(): int
    {
        return Config::NO_SEND_LIMIT;
    }

    /**
     * @return int|null
     */
    protected function getMaximum(): ?int
    {
        return Config::MAX_SEND_LIMIT_MINUTES;
    }
}
