<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Service;

use Centraliza\Connector\Core\Breaker\CircuitGate;
use Centraliza\Connector\Core\Model\Action;
use Centraliza\Connector\Core\Model\HeldEvent;
use Centraliza\Connector\Core\Model\OutcomeKind;
use Centraliza\Connector\Core\Policy\DeliveryPolicyInterface;
use Centraliza\Connector\Core\Port\ClockInterface;
use Centraliza\Connector\Core\Port\QuarantineStoreInterface;
use Centraliza\Connector\Core\Port\RetryStoreInterface;
use Centraliza\Connector\Core\Port\TransportInterface;

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
        private ClockInterface $clock
    ) {
    }

    /**
     * @param float $jitter a random number in [0, 1]
     */
    public function deliver(HeldEvent $held, float $jitter): Action
    {
        $now = $this->clock->now();

        if (!$this->gate->allow(self::CIRCUIT_SCOPE)) {
            $this->retryStore->hold(
                $held->event,
                $held->lane,
                $held->attempts,
                $now + self::DEFAULT_POSTPONE_SECONDS,
                'Circuit open.'
            );

            return Action::Postpone;
        }

        $outcome = $this->transport->send($held->event);
        $this->gate->report(self::CIRCUIT_SCOPE, $outcome);

        $attempts = $held->attempts + ($outcome->kind === OutcomeKind::Unavailable ? 1 : 0);
        $decision = $this->policy->decide($outcome, $attempts, $jitter);
        $error = trim($outcome->kind->value . ' ' . $outcome->detail);

        match ($decision->action) {
            Action::Delivered => null,
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
