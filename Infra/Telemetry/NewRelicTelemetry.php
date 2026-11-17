<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Telemetry;

use Centraliza\Connector\Core\Port\TelemetryInterface;
use Centraliza\Connector\Infra\Config\Settings;
use Throwable;

/**
 * Records custom events in New Relic. Without the agent, nothing happens.
 */
class NewRelicTelemetry implements TelemetryInterface
{
    /** @var array<string, scalar>|null */
    private ?array $common = null;

    public function __construct(
        private readonly NewRelicAgent $agent,
        private readonly Settings $settings
    ) {
    }

    public function event(string $name, array $attributes): void
    {
        try {
            if (!$this->agent->isAvailable()) {
                return;
            }
            $scalars = array_filter($attributes + $this->common(), static fn (mixed $value): bool => is_scalar($value));
            $this->agent->recordCustomEvent($name, $scalars);
        } catch (Throwable) {
            // Telemetry must not get in the way of what it observes.
        }
    }

    /**
     * Several stores can report under one New Relic application name, so every
     * event says which store it came from.
     *
     * @return array<string, scalar>
     */
    private function common(): array
    {
        return $this->common ??= [
            'storeHost' => $this->settings->reportingHost(),
            'mode' => $this->settings->isLive() ? Settings::MODE_LIVE : Settings::MODE_DRY_RUN,
            'environment' => $this->settings->environment(),
        ];
    }
}
