<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Breaker;

use Centraliza\Connector\Core\Model\Outcome;
use Centraliza\Connector\Core\Port\CircuitStoreInterface;
use Centraliza\Connector\Core\Port\ClockInterface;

/**
 * The circuit breaker as the sender uses it: ask before a request, tell after.
 * The rules live in CircuitBreaker; this class applies them to the shared state.
 */
final readonly class CircuitGate
{
    private const REPORT_ATTEMPTS = 3;

    public function __construct(
        private CircuitBreaker $breaker,
        private CircuitStoreInterface $store,
        private ClockInterface $clock
    ) {
    }

    /**
     * May a request for this scope go out now? When the wait of an open
     * circuit is over, exactly one caller gets true and becomes the probe.
     */
    public function allow(string $scope): bool
    {
        $state = $this->store->load($scope);
        [$allowed, $next] = $this->breaker->allow($state, $this->clock->now());

        if (!$allowed || $next->equals($state)) {
            return $allowed;
        }

        return $this->store->compareAndSet($scope, $state, $next);
    }

    /**
     * Would a request be let through now? Unlike allow(), this never makes the
     * caller the probe, so it is safe to ask before knowing there is something to send.
     */
    public function wouldAllow(string $scope): bool
    {
        return $this->breaker->allow($this->store->load($scope), $this->clock->now())[0];
    }

    /**
     * True when nothing is being held back. Reads the state without becoming the probe.
     */
    public function isClosed(string $scope): bool
    {
        return $this->store->load($scope)->status === CircuitStatus::Closed;
    }

    public function report(string $scope, Outcome $outcome): void
    {
        for ($i = 0; $i < self::REPORT_ATTEMPTS; $i++) {
            $state = $this->store->load($scope);
            $next = $this->breaker->report($state, $outcome, $this->clock->now());

            if ($next->equals($state) || $this->store->compareAndSet($scope, $state, $next)) {
                return;
            }
        }
        // Lost the race three times: other processes are reporting the same
        // outage or recovery, so the state is already moving the right way.
    }
}
