<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Time;

use Centraliza\Connector\Core\Port\ClockInterface;

class SystemClock implements ClockInterface
{
    public function now(): int
    {
        return time();
    }
}
