<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Service;

final readonly class Backoff
{
    public function __construct(
        private int $baseSeconds = 30,
        private int $maxSeconds = 3600
    ) {
    }

    /**
     * Doubles per attempt up to the cap, then spreads the result over its upper
     * half so rows that failed together do not come back together.
     *
     * @param int $attempt 1 for the first failure
     * @param float $jitter a random number in [0, 1], supplied by the caller
     */
    public function delaySeconds(int $attempt, float $jitter): int
    {
        $exponent = min(max($attempt, 1) - 1, 20);
        $ceiling = min($this->maxSeconds, $this->baseSeconds * (2 ** $exponent));
        $jitter = min(max($jitter, 0.0), 1.0);

        return (int)round($ceiling / 2 + ($ceiling / 2) * $jitter);
    }
}
