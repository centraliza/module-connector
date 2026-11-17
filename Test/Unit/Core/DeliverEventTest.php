<?php

declare(strict_types=1);

namespace Centraliza\Connector\Test\Unit\Core;

use Centraliza\Connector\Core\Breaker\CircuitBreaker;
use Centraliza\Connector\Core\Breaker\CircuitGate;
use Centraliza\Connector\Core\Breaker\CircuitState;
use Centraliza\Connector\Core\Model\Action;
use Centraliza\Connector\Core\Model\ConnectionEvent;
use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Core\Model\HeldEvent;
use Centraliza\Connector\Core\Model\Outcome;
use Centraliza\Connector\Core\Model\OutcomeKind;
use Centraliza\Connector\Core\Policy\ConnectionEventPolicy;
use Centraliza\Connector\Core\Port\CircuitStoreInterface;
use Centraliza\Connector\Core\Port\ClockInterface;
use Centraliza\Connector\Core\Port\QuarantineStoreInterface;
use Centraliza\Connector\Core\Port\RetryStoreInterface;
use Centraliza\Connector\Core\Port\TransportInterface;
use Centraliza\Connector\Core\Service\Backoff;
use Centraliza\Connector\Core\Service\DeliverEvent;
use PHPUnit\Framework\TestCase;

class DeliverEventTest extends TestCase
{
    private const NOW = 1000;

    /** @var array<int, array<int, mixed>> */
    private array $held = [];

    /** @var array<int, array<int, mixed>> */
    private array $quarantined = [];

    private int $sent = 0;

    public function testAnAcceptedEventLeavesNothingBehind(): void
    {
        self::assertSame(Action::Delivered, $this->service(Outcome::of(OutcomeKind::Accepted))->deliver($this->event(2), 0.0));
        self::assertSame([], $this->held);
        self::assertSame([], $this->quarantined);
    }

    public function testAnOutageIsHeldWithOneMoreAttemptAndABackoff(): void
    {
        $this->service(Outcome::unavailable(null, 'HTTP 503'))->deliver($this->event(2), 1.0);

        self::assertCount(1, $this->held);
        [, $lane, $attempts, $dueAt, $error] = $this->held[0];
        self::assertSame('bulk', $lane);
        self::assertSame(3, $attempts);
        self::assertSame(self::NOW + 120, $dueAt);
        self::assertSame('unavailable HTTP 503', $error);
    }

    public function testARateLimitIsHeldForRetryAfterWithoutSpendingAnAttempt(): void
    {
        $this->service(Outcome::rateLimited(90))->deliver($this->event(2), 0.0);

        self::assertSame(2, $this->held[0][2]);
        self::assertSame(self::NOW + 90, $this->held[0][3]);
    }

    public function testARefusedKeyWaitsAtLeastHalfAMinute(): void
    {
        $this->service(Outcome::of(OutcomeKind::Unauthorized))->deliver($this->event(0), 0.0);

        self::assertSame(self::NOW + 30, $this->held[0][3]);
    }

    public function testARejectedEventGoesToQuarantine(): void
    {
        self::assertSame(Action::Quarantine, $this->service(Outcome::of(OutcomeKind::Rejected, 'sku is required'))->deliver($this->event(0), 0.0));
        self::assertSame('rejected sku is required', $this->quarantined[0][3]);
        self::assertSame([], $this->held);
    }

    public function testTheLastAttemptOfAnOutageGoesToQuarantine(): void
    {
        self::assertSame(Action::Quarantine, $this->service(Outcome::unavailable())->deliver($this->event(7), 0.0));
        self::assertSame(8, $this->quarantined[0][2]);
    }

    public function testWithTheCircuitOpenNothingIsSentAndNoAttemptIsSpent(): void
    {
        $service = $this->service(Outcome::of(OutcomeKind::Unauthorized));
        $service->deliver($this->event(0), 0.0);
        $this->held = [];
        $this->sent = 0;

        self::assertSame(Action::Postpone, $service->deliver($this->event(4), 0.0));
        self::assertSame(0, $this->sent);
        self::assertSame(4, $this->held[0][2]);
        self::assertSame('Circuit open.', $this->held[0][4]);
    }

    private function event(int $attempts): HeldEvent
    {
        return new HeldEvent(
            new ConnectionEvent('products', EventVerb::Updated, ['sku' => 'A'], 'k', '2026-11-13T12:00:00Z'),
            'bulk',
            $attempts
        );
    }

    private function service(Outcome $outcome): DeliverEvent
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(self::NOW);
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('send')->willReturnCallback(function () use ($outcome) {
            $this->sent++;

            return $outcome;
        });
        $retry = $this->createMock(RetryStoreInterface::class);
        $retry->method('hold')->willReturnCallback(function (...$args): void {
            $this->held[] = $args;
        });
        $quarantine = $this->createMock(QuarantineStoreInterface::class);
        $quarantine->method('quarantine')->willReturnCallback(function (...$args): void {
            $this->quarantined[] = $args;
        });
        $store = new class implements CircuitStoreInterface {
            private ?CircuitState $state = null;

            public function load(string $scope): CircuitState
            {
                return $this->state ??= CircuitState::closed();
            }

            public function compareAndSet(string $scope, CircuitState $expected, CircuitState $next): bool
            {
                $this->state = $next;

                return true;
            }
        };

        return new DeliverEvent(
            new CircuitGate(new CircuitBreaker(), $store, $clock),
            $transport,
            new ConnectionEventPolicy(new Backoff(30, 3600), 8),
            $retry,
            $quarantine,
            $clock
        );
    }
}
