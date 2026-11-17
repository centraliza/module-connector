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
        private RecordReaderInterface $reader
    ) {
    }

    /**
     * @param float $jitter a random number in [0, 1]
     */
    public function deliver(HeldEvent $held, float $jitter): Action
    {
        // Asked first without becoming the probe: with the circuit open there is no point in reading the record.
        if (!$this->gate->wouldAllow(self::CIRCUIT_SCOPE)) {
            return $this->postponeForCircuit($held);
        }

        $event = $held->event;
        if ($event->verb !== EventVerb::Deleted && $this->reader->supports($event->stream)) {
            try {
                $record = $this->reader->read($event->stream, $event->key);
            } catch (Throwable $e) {
                // The store could not read its own record: an attempt is spent, but Centraliza is not to blame.
                // The message is the store's own, about its own record: it says what to fix.
                $reason = $e::class . ': ' . substr($e->getMessage(), 0, 150);

                return $this->settle($held, Outcome::unavailable(null, 'Could not read the record: ' . $reason), $jitter);
            }
            if ($record === null) {
                return Action::Skipped;
            }
            $event = $event->withRecord($record);
        }

        if (!$this->gate->allow(self::CIRCUIT_SCOPE)) {
            return $this->postponeForCircuit($held);
        }

        $outcome = $this->transport->send($event);
        $this->gate->report(self::CIRCUIT_SCOPE, $outcome);

        return $this->settle($held, $outcome, $jitter);
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
