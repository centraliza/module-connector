<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Policy;

use Centraliza\Connector\Core\Model\Action;
use Centraliza\Connector\Core\Model\Decision;
use Centraliza\Connector\Core\Model\Outcome;
use Centraliza\Connector\Core\Model\OutcomeKind;
use Centraliza\Connector\Core\Service\Backoff;

/**
 * Connection events only say "this record changed", and Centraliza reads the
 * record itself. Sending one twice is harmless, so an outage is retried: each
 * failure puts the event back with a longer delay, and once the attempts run
 * out it goes to quarantine. A rejected event goes there at once, because
 * sending the same body again cannot succeed.
 */
final readonly class ConnectionEventPolicy implements DeliveryPolicyInterface
{
    public function __construct(
        private Backoff $backoff,
        private int $maxAttempts = 8
    ) {
    }

    public function decide(Outcome $outcome, int $attempts, float $jitter): Decision
    {
        return match ($outcome->kind) {
            OutcomeKind::Accepted => new Decision(Action::Delivered),
            OutcomeKind::Rejected => new Decision(Action::Quarantine),
            OutcomeKind::RateLimited,
            OutcomeKind::Unauthorized,
            OutcomeKind::RouteRefused => new Decision(Action::Postpone, max(0, (int)$outcome->retryAfterSeconds)),
            OutcomeKind::Unavailable => $attempts >= $this->maxAttempts
                ? new Decision(Action::Quarantine)
                : new Decision(
                    Action::Retry,
                    max($this->backoff->delaySeconds($attempts, $jitter), (int)$outcome->retryAfterSeconds)
                ),
        };
    }
}
