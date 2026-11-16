<?php

declare(strict_types=1);

namespace Centraliza\Connector\Test\Unit\Cron;

use Centraliza\Connector\Core\Breaker\CircuitBreaker;
use Centraliza\Connector\Core\Breaker\CircuitGate;
use Centraliza\Connector\Core\Breaker\CircuitState;
use Centraliza\Connector\Core\Breaker\CircuitStatus;
use Centraliza\Connector\Core\Model\ConnectionEvent;
use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Core\Model\HeldEvent;
use Centraliza\Connector\Core\Port\CircuitStoreInterface;
use Centraliza\Connector\Core\Port\ClockInterface;
use Centraliza\Connector\Core\Port\RetryStoreInterface;
use Centraliza\Connector\Cron\RetryDue;
use Centraliza\Connector\Infra\Queue\QueuePublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class RetryDueTest extends TestCase
{
    private const NOW = 1_000_000;

    /** @var HeldEvent[] */
    private array $held = [];

    private int $published = 0;

    private int $now = self::NOW;

    public function testWithTheCircuitClosedEverythingDueGoesBackInOneRun(): void
    {
        $this->hold(1700);

        $this->cron(CircuitState::closed())->execute();

        self::assertSame(1700, $this->published);
        self::assertSame([], $this->held);
    }

    public function testWithTheCircuitOpenOnlyAFewGoBackToServeAsTheProbe(): void
    {
        $this->hold(1700);

        $this->cron(new CircuitState(CircuitStatus::Open, 0, 1, self::NOW + 30))->execute();

        self::assertSame(5, $this->published);
        self::assertCount(1695, $this->held);
    }

    public function testStopsWhenTheRunHasTakenTooLong(): void
    {
        $this->hold(1700);

        $this->cron(CircuitState::closed(), secondsPerBatch: 30)->execute();

        self::assertSame(1000, $this->published, 'two batches fit in the budget; the rest waits for the next run');
    }

    private function hold(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->held["id-$i"] = new HeldEvent(
                new ConnectionEvent('stock', EventVerb::Updated, ['sku' => "S$i", 'source_code' => 'default'], "id-$i", '2026-11-16T00:00:00Z'),
                'realtime',
                0
            );
        }
    }

    private function cron(CircuitState $state, int $secondsPerBatch = 0): RetryDue
    {
        $store = $this->createMock(RetryStoreInterface::class);
        $store->method('due')->willReturnCallback(function (int $now, int $limit) use ($secondsPerBatch): array {
            $this->now += $secondsPerBatch;

            return array_slice(array_values($this->held), 0, $limit);
        });
        $store->method('forget')->willReturnCallback(function (array $ids): void {
            foreach ($ids as $id) {
                unset($this->held[$id]);
            }
        });
        $publisher = $this->createMock(QueuePublisher::class);
        $publisher->method('publish')->willReturnCallback(function (): void {
            $this->published++;
        });
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturnCallback(fn () => $this->now);
        $circuit = $this->createMock(CircuitStoreInterface::class);
        $circuit->method('load')->willReturn($state);

        return new RetryDue($store, $publisher, $clock, new NullLogger(), new CircuitGate(new CircuitBreaker(), $circuit, $clock));
    }
}
