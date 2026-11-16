<?php

declare(strict_types=1);

namespace Centraliza\Connector\Test\Unit\Core;

use Centraliza\Connector\Core\Breaker\CircuitBreaker;
use Centraliza\Connector\Core\Breaker\CircuitState;
use Centraliza\Connector\Core\Breaker\CircuitStatus;
use Centraliza\Connector\Core\Model\Outcome;
use Centraliza\Connector\Core\Model\OutcomeKind;
use PHPUnit\Framework\TestCase;

class CircuitBreakerTest extends TestCase
{
    private const NOW = 1_000_000;

    private CircuitBreaker $breaker;

    protected function setUp(): void
    {
        $this->breaker = new CircuitBreaker(5, 30, 900, 60);
    }

    public function testStaysClosedBelowTheThresholdAndOpensOnTheFifthFailure(): void
    {
        $state = CircuitState::closed();
        for ($i = 1; $i <= 4; $i++) {
            $state = $this->breaker->report($state, Outcome::unavailable(), self::NOW);
            self::assertSame(CircuitStatus::Closed, $state->status);
            self::assertSame($i, $state->failures);
        }

        $state = $this->breaker->report($state, Outcome::unavailable(), self::NOW);

        self::assertSame(CircuitStatus::Open, $state->status);
        self::assertSame(self::NOW + 30, $state->openUntil);
        self::assertFalse($this->breaker->allow($state, self::NOW + 29)[0]);
    }

    public function testASuccessBetweenFailuresResetsTheCount(): void
    {
        $state = CircuitState::closed();
        for ($i = 0; $i < 4; $i++) {
            $state = $this->breaker->report($state, Outcome::unavailable(), self::NOW);
        }
        $state = $this->breaker->report($state, Outcome::of(OutcomeKind::Accepted), self::NOW);
        $state = $this->breaker->report($state, Outcome::unavailable(), self::NOW);

        self::assertSame(CircuitStatus::Closed, $state->status);
        self::assertSame(1, $state->failures);
    }

    /**
     * @dataProvider healthyAnswers
     */
    public function testAnyAnswerFromAHealthyServiceCloses(OutcomeKind $kind): void
    {
        $open = new CircuitState(CircuitStatus::HalfOpen, 0, 3, self::NOW, self::NOW + 60);

        self::assertEquals(CircuitState::closed(), $this->breaker->report($open, Outcome::of($kind), self::NOW));
    }

    public static function healthyAnswers(): array
    {
        return [
            [OutcomeKind::Accepted],
            [OutcomeKind::Rejected],
        ];
    }

    /**
     * @dataProvider refusals
     */
    public function testARefusedKeyOrRouteOpensAtOnce(OutcomeKind $kind): void
    {
        $state = $this->breaker->report(CircuitState::closed(), Outcome::of($kind), self::NOW);

        self::assertSame(CircuitStatus::Open, $state->status);
        self::assertSame(self::NOW + 30, $state->openUntil);
    }

    public static function refusals(): array
    {
        return [[OutcomeKind::Unauthorized], [OutcomeKind::RouteRefused]];
    }

    public function testRateLimitPausesSendingForAsLongAsAskedWithoutCountingAsAnOutage(): void
    {
        $state = new CircuitState(CircuitStatus::Closed, 3, 1);

        $paused = $this->breaker->report($state, Outcome::rateLimited(40), self::NOW);

        self::assertSame(CircuitStatus::Open, $paused->status);
        self::assertSame(self::NOW + 40, $paused->openUntil);
        self::assertSame(1, $paused->level, 'the next real outage does not wait longer because of a 429');
    }

    public function testRateLimitWithoutRetryAfterPausesBriefly(): void
    {
        $paused = $this->breaker->report(CircuitState::closed(), Outcome::rateLimited(null), self::NOW);

        self::assertSame(self::NOW + 10, $paused->openUntil);
    }

    public function testARateLimitedProbeDoesNotLeaveTheCircuitHalfOpen(): void
    {
        $probing = new CircuitState(CircuitStatus::HalfOpen, 0, 2, self::NOW - 1, self::NOW + 60);

        $paused = $this->breaker->report($probing, Outcome::rateLimited(20), self::NOW);

        self::assertSame(CircuitStatus::Open, $paused->status);
        self::assertSame(self::NOW + 20, $paused->openUntil);
        [$allowed] = $this->breaker->allow($paused, self::NOW + 20);
        self::assertTrue($allowed, 'a new probe goes out as soon as the pause ends');
    }

    public function testARateLimitNeverShortensAnOutageWait(): void
    {
        $open = new CircuitState(CircuitStatus::Open, 0, 3, self::NOW + 200);

        self::assertSame(self::NOW + 200, $this->breaker->report($open, Outcome::rateLimited(5), self::NOW)->openUntil);
    }

    public function testOnlyOneProbeIsLetThroughWhenTheWaitEnds(): void
    {
        $open = new CircuitState(CircuitStatus::Open, 0, 1, self::NOW);

        [$allowed, $probing] = $this->breaker->allow($open, self::NOW);
        self::assertTrue($allowed);
        self::assertSame(CircuitStatus::HalfOpen, $probing->status);
        self::assertSame(self::NOW + 60, $probing->probeUntil);

        [$secondAllowed, $unchanged] = $this->breaker->allow($probing, self::NOW + 1);
        self::assertFalse($secondAllowed);
        self::assertSame($probing, $unchanged);
    }

    public function testAProbeThatNeverReportsBackIsReplacedAfterItsTimeout(): void
    {
        $probing = new CircuitState(CircuitStatus::HalfOpen, 0, 1, self::NOW, self::NOW + 60);

        [$allowed, $next] = $this->breaker->allow($probing, self::NOW + 60);

        self::assertTrue($allowed);
        self::assertSame(self::NOW + 120, $next->probeUntil);
    }

    public function testAFailedProbeReopensForTwiceAsLongUpToTheCap(): void
    {
        $state = CircuitState::closed();
        $expected = [30, 60, 120, 240, 480, 900, 900];
        $now = self::NOW;

        foreach ($expected as $duration) {
            $state = $state->status === CircuitStatus::Closed
                ? $this->breaker->report($state, Outcome::of(OutcomeKind::Unauthorized), $now)
                : $this->breaker->report($this->breaker->allow($state, $now)[1], Outcome::unavailable(), $now);

            self::assertSame(CircuitStatus::Open, $state->status);
            self::assertSame($now + $duration, $state->openUntil);
            $now = $state->openUntil;
        }
    }

    public function testRetryAfterKeepsTheCircuitOpenLonger(): void
    {
        $state = $this->breaker->report(
            new CircuitState(CircuitStatus::Closed, 4),
            Outcome::unavailable(300),
            self::NOW
        );

        self::assertSame(self::NOW + 300, $state->openUntil);
    }

    public function testALateFailureWhileOpenDoesNotExtendTheWait(): void
    {
        $open = new CircuitState(CircuitStatus::Open, 0, 1, self::NOW + 30);

        self::assertSame($open, $this->breaker->report($open, Outcome::unavailable(), self::NOW + 10));
    }

    public function testAfterRecoveryTheNextOutageStartsFromTheShortWaitAgain(): void
    {
        $recovered = $this->breaker->report(
            new CircuitState(CircuitStatus::HalfOpen, 0, 5, self::NOW, self::NOW + 60),
            Outcome::of(OutcomeKind::Accepted),
            self::NOW
        );
        $state = $this->breaker->report($recovered, Outcome::of(OutcomeKind::Unauthorized), self::NOW);

        self::assertSame(self::NOW + 30, $state->openUntil);
    }
}
