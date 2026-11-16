<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Port;

interface ClockInterface
{
    /**
     * @return int unix seconds
     */
    public function now(): int;
}
