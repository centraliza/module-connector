<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Config\Backend\AbandonedCart;

use Centraliza\Connector\Messaging\Model\Config\Backend\IntegerRangeValue;
use Centraliza\Connector\Messaging\Model\AbandonedCart\Config;

class Minutes extends IntegerRangeValue
{
    /**
     * @return int
     */
    protected function getMinimum(): int
    {
        return Config::MIN_ABANDON_MINUTES;
    }

    /**
     * @return int|null
     */
    protected function getMaximum(): ?int
    {
        // Capped at the longest send limit even when there is none: a larger value would overflow the
        // selection window and silently stop every send of the website.
        return Config::MAX_ABANDON_MINUTES;
    }
}
