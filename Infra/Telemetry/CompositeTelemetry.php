<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Telemetry;

use Centraliza\Connector\Core\Port\TelemetryInterface;

/**
 * Tells every destination configured in di.xml.
 */
class CompositeTelemetry implements TelemetryInterface
{
    /**
     * @param TelemetryInterface[] $destinations
     */
    public function __construct(
        private readonly array $destinations = []
    ) {
    }

    public function event(string $name, array $attributes): void
    {
        foreach ($this->destinations as $destination) {
            $destination->event($name, $attributes);
        }
    }
}
