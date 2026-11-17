<?php

declare(strict_types=1);

namespace Centraliza\Connector\Test\Unit\Core;

use Centraliza\Connector\Core\Model\Action;
use Centraliza\Connector\Core\Model\Outcome;
use Centraliza\Connector\Core\Model\OutcomeKind;
use Centraliza\Connector\Core\Policy\ConnectionEventPolicy;
use Centraliza\Connector\Core\Service\Backoff;
use PHPUnit\Framework\TestCase;

class ConnectionEventPolicyTest extends TestCase
{
    /**
     * @var ConnectionEventPolicy
     */
    private ConnectionEventPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ConnectionEventPolicy(new Backoff(30, 3600), 8);
    }

    public function testAnAcceptedEventIsDone(): void
    {
        self::assertSame(Action::Delivered, $this->policy->decide(Outcome::of(OutcomeKind::Accepted), 3, 0.0)->action);
    }

    public function testARejectedEventIsQuarantinedAtOnce(): void
    {
        self::assertSame(Action::Quarantine, $this->policy->decide(Outcome::of(OutcomeKind::Rejected), 1, 0.0)->action);
    }

    /**
     * @dataProvider keyOrRouteProblems
     */
    public function testKeyAndRouteProblemsNeverSpendAnAttempt(Outcome $outcome, int $expectedDelay): void
    {
        $decision = $this->policy->decide($outcome, 99, 0.0);

        self::assertSame(Action::Postpone, $decision->action);
        self::assertSame($expectedDelay, $decision->delaySeconds);
    }

    public static function keyOrRouteProblems(): array
    {
        return [
            '429 with Retry-After' => [Outcome::rateLimited(42), 42],
            '429 without Retry-After' => [Outcome::rateLimited(null), 0],
            '401' => [Outcome::of(OutcomeKind::Unauthorized), 0],
            '403 or 404' => [Outcome::of(OutcomeKind::RouteRefused), 0],
        ];
    }

    public function testAnOutageRetriesWithGrowingDelay(): void
    {
        $first = $this->policy->decide(Outcome::unavailable(), 1, 1.0);
        $third = $this->policy->decide(Outcome::unavailable(), 3, 1.0);

        self::assertSame(Action::Retry, $first->action);
        self::assertSame(30, $first->delaySeconds);
        self::assertSame(120, $third->delaySeconds);
    }

    public function testRetryAfterFromTheServerWinsWhenLonger(): void
    {
        self::assertSame(600, $this->policy->decide(Outcome::unavailable(600), 1, 1.0)->delaySeconds);
        self::assertSame(30, $this->policy->decide(Outcome::unavailable(5), 1, 1.0)->delaySeconds);
    }

    public function testAnOutageIsQuarantinedOnlyAfterTheLastAttempt(): void
    {
        self::assertSame(Action::Retry, $this->policy->decide(Outcome::unavailable(), 7, 0.0)->action);
        self::assertSame(Action::Quarantine, $this->policy->decide(Outcome::unavailable(), 8, 0.0)->action);
    }
}
