<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Policy;

use Centraliza\Connector\Core\Model\Decision;
use Centraliza\Connector\Core\Model\Outcome;

interface DeliveryPolicyInterface
{
    /**
     * @param int $attempts failed attempts already counted, this one included
     * @param float $jitter a random number in [0, 1]
     */
    public function decide(Outcome $outcome, int $attempts, float $jitter): Decision;
}
