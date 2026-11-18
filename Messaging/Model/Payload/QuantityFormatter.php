<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Payload;

/**
 * Quantities as the Centraliza events carry them: a whole quantity as an integer, a fractional one as it is.
 */
class QuantityFormatter
{
    /**
     * @param float $quantity
     * @return int|float
     */
    public function format(float $quantity): int|float
    {
        if ($quantity === floor($quantity)) {
            return (int) $quantity;
        }

        return $quantity;
    }
}
