<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Service;

use Centraliza\Connector\Core\Breaker\CircuitGate;
use Centraliza\Connector\Core\Model\Action;
use Centraliza\Connector\Core\Model\ConnectionEvent;
use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Core\Model\HeldEvent;
use Centraliza\Connector\Core\Model\Outcome;
use Centraliza\Connector\Core\Model\OutcomeKind;
use Centraliza\Connector\Core\Policy\DeliveryPolicyInterface;
use Centraliza\Connector\Core\Port\ClockInterface;
use Centraliza\Connector\Core\Port\QuarantineStoreInterface;
use Centraliza\Connector\Core\Port\RecordReaderInterface;
use Centraliza\Connector\Core\Port\RetryStoreInterface;
use Centraliza\Connector\Core\Port\TelemetryInterface;
use Centraliza\Connector\Core\Port\TransportInterface;
use Throwable;

/**
 * What the consumer does with one event taken from the queue.
 */
final readonly class DeliverEvent
{
    public const CIRCUIT_SCOPE = 'centraliza';

    /** How long an event waits when the circuit is open or the key is refused and no delay was given. */
    private const DEFAULT_POSTPONE_SECONDS = 30;

    public function __construct(
        private CircuitGate $gate,
        private TransportInterface $transport,
        private DeliveryPolicyInterface $policy,
        private RetryStoreInterface $retryStore,
        private QuarantineStoreInterface $quarantine,
        private ClockInterface $clock,
        private RecordReaderInterface $reader,
        private TelemetryInterface $telemetry
    ) {
    }

    /**
     * @param float $jitter a random number in [0, 1]
     */
    public function deliver(HeldEvent $held, float $jitter): Action
    {
        // Asked first without becoming the probe: with the circuit open there is no point in reading the record.
        if (!$this->gate->wouldAllow(self::CIRCUIT_SCOPE)) {
            return $this->tell($held, $this->postponeForCircuit($held), 'circuit_open');
        }

        $event = $held->event;
        $readMs = null;
        if ($event->verb !== EventVerb::Deleted && $this->reader->supports($event->stream)) {
            $startedAt = hrtime(true);
            try {
                $record = $this->reader->read($event->stream, $event->key);
            } catch (Throwable $e) {
                // The store could not read its own record: an attempt is spent, but Centraliza is not to blame.
                // The message is the store's own, about its own record: it says what to fix.
                $reason = $e::class . ': ' . substr($e->getMessage(), 0, 150);
                $action = $this->settle($held, Outcome::unavailable(null, 'Could not read the record: ' . $reason), $jitter);

                return $this->tell($held, $action, 'record_unreadable', $reason, $this->msSince($startedAt));
            }
            $readMs = $this->msSince($startedAt);
            if ($record === null) {
                return $this->tell($held, Action::Skipped, 'record_gone', '', $readMs);
            }
            $event = $event->withRecord($record);
        }

        if (!$this->gate->allow(self::CIRCUIT_SCOPE)) {
            return $this->tell($held, $this->postponeForCircuit($held), 'circuit_open', '', $readMs);
        }

        $startedAt = hrtime(true);
        $outcome = $this->transport->send($event);
        $sendMs = $this->msSince($startedAt);
        $this->gate->report(self::CIRCUIT_SCOPE, $outcome);

        return $this->tell($held, $this->settle($held, $outcome, $jitter), $outcome->kind->value, $outcome->detail, $readMs, $sendMs);
    }

    /**
     * @param string $outcome how the send was answered, or why nothing was sent
     */
    private function tell(
        HeldEvent $held,
        Action $action,
        string $outcome,
        string $detail = '',
        ?int $readMs = null,
        ?int $sendMs = null
    ): Action {
        $occurredAt = strtotime($held->event->occurredAt);
        $this->telemetry->event(TelemetryInterface::DELIVERY, [
            'stream' => $held->event->stream,
            'verb' => $held->event->verb->value,
            'lane' => $held->lane,
            'action' => $action->value,
            'outcome' => $outcome,
            'detail' => substr($detail, 0, 200),
            // Failed sends this event has had before this one.
            'attempts' => $held->attempts,
            'readMs' => $readMs,
            'sendMs' => $sendMs,
            // From the change in the store until now.
            'lagSeconds' => $occurredAt === false ? null : max(0, $this->clock->now() - $occurredAt),
        ]);

        return $action;
    }

    private function msSince(int|float $startedAt): int
    {
        return (int)round((hrtime(true) - $startedAt) / 1_000_000);
    }

    private function postponeForCircuit(HeldEvent $held): Action
    {
        $this->retryStore->hold(
            $held->event,
            $held->lane,
            $held->attempts,
            $this->clock->now() + self::DEFAULT_POSTPONE_SECONDS,
            'Circuit open.'
        );

        return Action::Postpone;
    }

    /**
     * Only the key is kept for later: the record is read again on the next attempt.
     */
    private function settle(HeldEvent $held, Outcome $outcome, float $jitter): Action
    {
        $now = $this->clock->now();
        $attempts = $held->attempts + ($outcome->kind === OutcomeKind::Unavailable ? 1 : 0);
        $decision = $this->policy->decide($outcome, $attempts, $jitter);
        $error = trim($outcome->kind->value . ' ' . $outcome->detail);

        match ($decision->action) {
            Action::Delivered, Action::Skipped => null,
            Action::Retry => $this->retryStore->hold(
                $held->event,
                $held->lane,
                $attempts,
                $now + $decision->delaySeconds,
                $error
            ),
            Action::Postpone => $this->retryStore->hold(
                $held->event,
                $held->lane,
                $attempts,
                $now + max($decision->delaySeconds, self::DEFAULT_POSTPONE_SECONDS),
                $error
            ),
            Action::Quarantine => $this->quarantine->quarantine($held->event, $held->lane, $attempts, $error),
        };

        return $decision->action;
    }
}
