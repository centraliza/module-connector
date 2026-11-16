<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Breaker;

/**
 * One row of the circuit table. Times are unix seconds.
 */
final readonly class CircuitState
{
    public function __construct(
        public CircuitStatus $status = CircuitStatus::Closed,
        /** Consecutive failures while closed. */
        public int $failures = 0,
        /** How many times in a row the circuit has opened; drives how long it stays open. */
        public int $level = 0,
        public int $openUntil = 0,
        /** While half open, nobody else may probe before this time. */
        public int $probeUntil = 0
    ) {
    }

    public static function closed(): self
    {
        return new self();
    }

    public function equals(self $other): bool
    {
        return $this->status === $other->status
            && $this->failures === $other->failures
            && $this->level === $other->level
            && $this->openUntil === $other->openUntil
            && $this->probeUntil === $other->probeUntil;
    }
}
