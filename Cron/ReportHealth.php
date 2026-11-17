<?php

declare(strict_types=1);

namespace Centraliza\Connector\Cron;

use Centraliza\Connector\Core\Port\CircuitStoreInterface;
use Centraliza\Connector\Core\Port\ClockInterface;
use Centraliza\Connector\Core\Port\SettingsInterface;
use Centraliza\Connector\Core\Port\TelemetryInterface;
use Centraliza\Connector\Core\Service\DeliverEvent;
use Centraliza\Connector\Infra\Persistence\QuarantineStore;
use Centraliza\Connector\Infra\Persistence\RetryStore;
use Centraliza\Connector\Infra\Queue\QueueDepth;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Once a minute, tells how much is waiting, held and quarantined. The
 * absence of this event is itself a signal: cron is not running.
 */
class ReportHealth
{
    private const LANES = ['realtime', 'bulk'];

    public function __construct(
        private readonly SettingsInterface $settings,
        private readonly TelemetryInterface $telemetry,
        private readonly CircuitStoreInterface $circuit,
        private readonly RetryStore $retryStore,
        private readonly QuarantineStore $quarantine,
        private readonly QueueDepth $queueDepth,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->settings->isEnabled()) {
            return;
        }
        try {
            $now = $this->clock->now();
            $state = $this->circuit->load(DeliverEvent::CIRCUIT_SCOPE);
            $held = $this->retryStore->summary();
            $waiting = $this->queueDepth->byLane();

            $attributes = [
                'circuit' => $state->status->value,
                'circuitLevel' => $state->level,
                'held' => $held['count'],
                // How long the event held the longest has been waiting.
                'heldOldestSeconds' => $held['oldest'] === null ? 0 : max(0, $now - $held['oldest']),
                'quarantined' => $this->quarantine->count(),
            ];
            if ($waiting !== null) {
                foreach (self::LANES as $lane) {
                    $attributes['waiting' . ucfirst($lane)] = $waiting[$lane] ?? 0;
                }
            }
            $this->telemetry->event(TelemetryInterface::HEALTH, $attributes);
        } catch (Throwable $e) {
            $this->logger->warning('Centraliza Connector could not report its health.', ['exception' => $e]);
        }
    }
}
