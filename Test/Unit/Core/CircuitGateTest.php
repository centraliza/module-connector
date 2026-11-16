<?php

declare(strict_types=1);

namespace Centraliza\Connector\Test\Unit\Core;

use Centraliza\Connector\Core\Breaker\CircuitBreaker;
use Centraliza\Connector\Core\Breaker\CircuitGate;
use Centraliza\Connector\Core\Breaker\CircuitState;
use Centraliza\Connector\Core\Breaker\CircuitStatus;
use Centraliza\Connector\Core\Model\Outcome;
use Centraliza\Connector\Core\Model\OutcomeKind;
use Centraliza\Connector\Core\Port\CircuitStoreInterface;
use Centraliza\Connector\Core\Port\ClockInterface;
use PHPUnit\Framework\TestCase;

class CircuitGateTest extends TestCase
{
    private const SCOPE = 'host';

    public function testStaysOpenForEveryoneUntilTheWaitEndsThenLetsOneProbeThrough(): void
    {
        $store = $this->store();
        $clock = $this->clock(1000);
        $gate = new CircuitGate(new CircuitBreaker(5, 30, 900, 60), $store, $clock);

        for ($i = 0; $i < 5; $i++) {
            self::assertTrue($gate->allow(self::SCOPE));
            $gate->report(self::SCOPE, Outcome::unavailable());
        }
        self::assertFalse($gate->allow(self::SCOPE));

        $clock->time = 1030;
        self::assertTrue($gate->allow(self::SCOPE), 'the first caller after the wait is the probe');
        self::assertFalse($gate->allow(self::SCOPE), 'nobody else may send while the probe is out');

        $gate->report(self::SCOPE, Outcome::of(OutcomeKind::Accepted));
        self::assertTrue($gate->allow(self::SCOPE));
        self::assertTrue($store->states[self::SCOPE]->equals(CircuitState::closed()));
    }

    public function testTheProcessThatLosesTheRaceForTheProbeIsNotAllowed(): void
    {
        $store = $this->store();
        $store->states[self::SCOPE] = new CircuitState(CircuitStatus::Open, 0, 1, 1000);
        $store->loseNextWrites = 1;
        $gate = new CircuitGate(new CircuitBreaker(), $store, $this->clock(1000));

        self::assertFalse($gate->allow(self::SCOPE));
    }

    public function testAReportIsAppliedAgainOnTopOfWhatAnotherProcessWrote(): void
    {
        $store = $this->store();
        $store->states[self::SCOPE] = new CircuitState(CircuitStatus::Closed, 2);
        $store->loseNextWrites = 1;
        $store->writtenByOther = new CircuitState(CircuitStatus::Closed, 3);
        $gate = new CircuitGate(new CircuitBreaker(5), $store, $this->clock(1000));

        $gate->report(self::SCOPE, Outcome::unavailable());

        self::assertSame(4, $store->states[self::SCOPE]->failures);
    }

    public function testASuccessWhileAlreadyClosedWritesNothing(): void
    {
        $store = $this->store();
        $gate = new CircuitGate(new CircuitBreaker(), $store, $this->clock(1000));

        $gate->report(self::SCOPE, Outcome::of(OutcomeKind::Accepted));

        self::assertSame(0, $store->writes);
    }

    private function clock(int $time): ClockInterface
    {
        return new class ($time) implements ClockInterface {
            public function __construct(public int $time)
            {
            }

            public function now(): int
            {
                return $this->time;
            }
        };
    }

    private function store(): CircuitStoreInterface
    {
        return new class implements CircuitStoreInterface {
            /** @var array<string, CircuitState> */
            public array $states = [];
            public int $loseNextWrites = 0;
            public ?CircuitState $writtenByOther = null;
            public int $writes = 0;

            public function load(string $scope): CircuitState
            {
                return $this->states[$scope] ??= CircuitState::closed();
            }

            public function compareAndSet(string $scope, CircuitState $expected, CircuitState $next): bool
            {
                if ($this->loseNextWrites > 0) {
                    $this->loseNextWrites--;
                    if ($this->writtenByOther !== null) {
                        $this->states[$scope] = $this->writtenByOther;
                    }

                    return false;
                }
                if (!$this->load($scope)->equals($expected)) {
                    return false;
                }
                $this->states[$scope] = $next;
                $this->writes++;

                return true;
            }
        };
    }
}
