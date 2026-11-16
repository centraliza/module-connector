<?php

declare(strict_types=1);

namespace Centraliza\Connector\Cron;

use Centraliza\Connector\Core\Breaker\CircuitGate;
use Centraliza\Connector\Core\Port\ClockInterface;
use Centraliza\Connector\Core\Port\RetryStoreInterface;
use Centraliza\Connector\Core\Service\DeliverEvent;
use Centraliza\Connector\Infra\Queue\QueuePublisher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Puts back on the queue the events whose wait is over.
 */
class RetryDue
{
    private const BATCH = 500;

    /** While the circuit is not closed, only enough events to serve as the probe go back. */
    private const PROBE_BATCH = 5;

    /** Leaves room for the next run of a cron that fires every minute. */
    private const TIME_BUDGET_SECONDS = 45;

    public function __construct(
        private readonly RetryStoreInterface $retryStore,
        private readonly QueuePublisher $publisher,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly CircuitGate $gate
    ) {
    }

    public function execute(): void
    {
        $startedAt = $this->clock->now();
        try {
            if (!$this->gate->isClosed(DeliverEvent::CIRCUIT_SCOPE)) {
                // Everything republished now would be held again as soon as the consumer took it.
                $this->republish($startedAt, self::PROBE_BATCH);

                return;
            }
            // After an outage thousands of events fall due together: drain them in this run.
            while ($this->republish($startedAt, self::BATCH) === self::BATCH
                && $this->clock->now() - $startedAt < self::TIME_BUDGET_SECONDS
            ) {
                continue;
            }
        } catch (Throwable $e) {
            // The queue is still unavailable: what was not published stays stored for the next run.
            $this->logger->warning('Centraliza Connector could not republish held events.', ['exception' => $e]);
        }
    }

    /**
     * @return int how many events were due, up to the limit
     */
    private function republish(int $dueBy, int $limit): int
    {
        $published = [];
        try {
            // Due as of the start of the run: an event held again meanwhile is not picked up twice.
            $due = $this->retryStore->due($dueBy, $limit);
            foreach ($due as $held) {
                $this->publisher->publish($held->lane, $held->event, $held->attempts);
                $published[] = $held->event->idempotencyKey;
            }
        } finally {
            $this->retryStore->forget($published);
        }

        return count($due);
    }
}
