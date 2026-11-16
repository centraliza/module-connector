<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Breaker;

use Centraliza\Connector\Core\Model\Outcome;
use Centraliza\Connector\Core\Model\OutcomeKind;

/**
 * The transition rules of the consumer-side circuit breaker, as pure functions
 * of (state, input, time). Whoever stores the state applies the result with a
 * compare-and-set, so two processes cannot both become the half-open probe.
 */
final readonly class CircuitBreaker
{
    public function __construct(
        private int $failureThreshold = 5,
        private int $baseOpenSeconds = 30,
        private int $maxOpenSeconds = 900,
        private int $probeTimeoutSeconds = 60,
        private int $rateLimitPauseSeconds = 10
    ) {
    }

    /**
     * May a request go out now? When the returned state differs from the one
     * given, the caller is the probe and must persist it before sending.
     *
     * @return array{0: bool, 1: CircuitState}
     */
    public function allow(CircuitState $state, int $now): array
    {
        return match ($state->status) {
            CircuitStatus::Closed => [true, $state],
            CircuitStatus::Open => $now < $state->openUntil
                ? [false, $state]
                : [true, $this->probing($state, $now)],
            CircuitStatus::HalfOpen => $now < $state->probeUntil
                ? [false, $state]
                : [true, $this->probing($state, $now)],
        };
    }

    public function report(CircuitState $state, Outcome $outcome, int $now): CircuitState
    {
        if ($outcome->kind->provesServiceHealthy()) {
            return CircuitState::closed();
        }

        return match ($outcome->kind) {
            OutcomeKind::RateLimited => $this->paused($state, $outcome, $now),
            OutcomeKind::Unauthorized, OutcomeKind::RouteRefused => $this->opened($state, $outcome, $now),
            default => $this->afterFailure($state, $outcome, $now),
        };
    }

    private function afterFailure(CircuitState $state, Outcome $outcome, int $now): CircuitState
    {
        if ($state->status === CircuitStatus::Open) {
            return $state;
        }
        if ($state->status === CircuitStatus::HalfOpen) {
            return $this->opened($state, $outcome, $now);
        }

        $failures = $state->failures + 1;
        if ($failures >= $this->failureThreshold) {
            return $this->opened($state, $outcome, $now);
        }

        return new CircuitState(CircuitStatus::Closed, $failures, $state->level);
    }

    private function opened(CircuitState $state, Outcome $outcome, int $now): CircuitState
    {
        $duration = min($this->maxOpenSeconds, $this->baseOpenSeconds * (2 ** min($state->level, 20)));
        $duration = max($duration, (int)$outcome->retryAfterSeconds);

        return new CircuitState(CircuitStatus::Open, 0, $state->level + 1, $now + $duration);
    }

    /**
     * A 429 is an answer from a healthy service: sending stops for as long as
     * it asked, for every process, and that is not counted as an outage. It
     * also settles a probe, which would otherwise hold the circuit half open
     * until its timeout.
     */
    private function paused(CircuitState $state, Outcome $outcome, int $now): CircuitState
    {
        $until = $now + max($this->rateLimitPauseSeconds, (int)$outcome->retryAfterSeconds);
        if ($state->status === CircuitStatus::Open) {
            $until = max($until, $state->openUntil);
        }

        return new CircuitState(CircuitStatus::Open, 0, $state->level, $until);
    }

    private function probing(CircuitState $state, int $now): CircuitState
    {
        return new CircuitState(
            CircuitStatus::HalfOpen,
            0,
            $state->level,
            $state->openUntil,
            $now + $this->probeTimeoutSeconds
        );
    }
}
