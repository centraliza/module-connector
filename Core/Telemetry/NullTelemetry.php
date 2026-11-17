<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Telemetry;

use Centraliza\Connector\Core\Port\TelemetryInterface;

final class NullTelemetry implements TelemetryInterface
{
    public function event(string $name, array $attributes): void
    {
    }
}
