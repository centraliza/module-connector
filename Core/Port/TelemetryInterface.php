<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Port;

/**
 * Where the module tells what it is doing, for dashboards and alerts.
 */
interface TelemetryInterface
{
    /** One per event taken from the queue: what was done with it and how long it took. */
    public const DELIVERY = 'CentralizaDelivery';
    /** One each time the circuit breaker changes state. */
    public const CIRCUIT = 'CentralizaCircuit';
    /** One per minute: how much is waiting, held and quarantined. */
    public const HEALTH = 'CentralizaHealth';

    /**
     * Never throws: telemetry must not get in the way of what it observes.
     *
     * @param array<string, scalar|null> $attributes never the record itself, which can carry personal data
     */
    public function event(string $name, array $attributes): void;
}
