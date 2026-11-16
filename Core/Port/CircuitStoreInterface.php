<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Port;

use Centraliza\Connector\Core\Breaker\CircuitState;

/**
 * Circuit state shared by every process that sends to Centraliza.
 */
interface CircuitStoreInterface
{
    /**
     * A scope nobody has seen yet is closed.
     */
    public function load(string $scope): CircuitState;

    /**
     * Writes $next only if the stored state still equals $expected.
     *
     * @return bool false when another process changed the state first
     */
    public function compareAndSet(string $scope, CircuitState $expected, CircuitState $next): bool;
}
