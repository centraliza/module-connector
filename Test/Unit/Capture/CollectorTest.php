<?php

declare(strict_types=1);

namespace Centraliza\Connector\Test\Unit\Capture;

use Centraliza\Connector\Capture\Collector;
use Centraliza\Connector\Core\Model\ConnectionEvent;
use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Core\Port\ClockInterface;
use Centraliza\Connector\Core\Port\EventPublisherInterface;
use Centraliza\Connector\Core\Port\IdGeneratorInterface;
use Centraliza\Connector\Core\Port\SettingsInterface;
use Centraliza\Connector\Core\Port\TransactionStateInterface;
use Centraliza\Connector\Core\Stream\StreamRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class CollectorTest extends TestCase
{
    /** @var array<int, array{0: string, 1: ConnectionEvent}> */
    private array $published = [];

    private bool $inTransaction = false;

    private bool $enabled = true;

    private bool $publisherFails = false;

    public function testOutsideATransactionTheEventGoesOutAtOnce(): void
    {
        $this->collector()->record('stock', EventVerb::Updated, ['source_code' => 'default', 'sku' => 'A', 'x' => 1]);

        self::assertCount(1, $this->published);
        [$lane, $event] = $this->published[0];
        self::assertSame('realtime', $lane);
        self::assertSame('stock.updated', $event->type());
        self::assertSame(['sku' => 'A', 'source_code' => 'default'], $event->key);
        self::assertSame('2026-11-13T12:00:00Z', $event->occurredAt);
    }

    public function testInsideATransactionNothingGoesOutUntilTheCommit(): void
    {
        $collector = $this->collector();
        $this->inTransaction = true;
        $collector->record('products', EventVerb::Updated, ['sku' => 'A']);
        $collector->record('products', EventVerb::Updated, ['sku' => 'B']);
        self::assertSame([], $this->published);

        $collector->flush();

        self::assertCount(2, $this->published);
        self::assertSame('bulk', $this->published[0][0]);
    }

    public function testARollbackDropsWhatWasRecorded(): void
    {
        $collector = $this->collector();
        $this->inTransaction = true;
        $collector->record('products', EventVerb::Updated, ['sku' => 'A']);

        $collector->discard();
        $collector->flush();

        self::assertSame([], $this->published);
    }

    /**
     * @dataProvider verbSequences
     */
    public function testTheSameRecordIsReportedOncePerTransaction(array $verbs, EventVerb $expected): void
    {
        $collector = $this->collector();
        $this->inTransaction = true;
        foreach ($verbs as $verb) {
            $collector->record('products', $verb, ['sku' => 'A']);
        }

        $collector->flush();

        self::assertCount(1, $this->published);
        self::assertSame($expected, $this->published[0][1]->verb);
    }

    public static function verbSequences(): array
    {
        return [
            'saved three times' => [[EventVerb::Updated, EventVerb::Updated, EventVerb::Updated], EventVerb::Updated],
            'created then updated' => [[EventVerb::Created, EventVerb::Updated], EventVerb::Created],
            'updated then deleted' => [[EventVerb::Updated, EventVerb::Deleted], EventVerb::Deleted],
            'deleted then touched again' => [[EventVerb::Deleted, EventVerb::Updated], EventVerb::Deleted],
        ];
    }

    public function testAnOptionalKeyFieldTravelsOnlyWhenItHasAValue(): void
    {
        $collector = $this->collector();
        $collector->record('products', EventVerb::Updated, ['sku' => 'NEW', 'previous_sku' => 'OLD']);
        $collector->record('products', EventVerb::Updated, ['sku' => 'A', 'previous_sku' => '']);

        self::assertSame(['sku' => 'NEW', 'previous_sku' => 'OLD'], $this->published[0][1]->key);
        self::assertSame(['sku' => 'A'], $this->published[1][1]->key);
    }

    public function testEachEventGetsItsOwnIdempotencyKey(): void
    {
        $collector = $this->collector();
        $collector->record('products', EventVerb::Updated, ['sku' => 'A']);
        $collector->record('products', EventVerb::Updated, ['sku' => 'A']);

        self::assertNotSame($this->published[0][1]->idempotencyKey, $this->published[1][1]->idempotencyKey);
    }

    public function testNothingIsRecordedWhileTheModuleIsOff(): void
    {
        $this->enabled = false;

        $this->collector()->record('products', EventVerb::Updated, ['sku' => 'A']);

        self::assertSame([], $this->published);
    }

    /**
     * @dataProvider badInput
     */
    public function testAHookWithBadInputNeverBreaksTheSave(string $stream, array $key): void
    {
        $this->collector()->record($stream, EventVerb::Updated, $key);

        self::assertSame([], $this->published);
    }

    public static function badInput(): array
    {
        return [
            'unknown stream' => ['unknown', ['sku' => 'A']],
            'missing key field' => ['stock', ['sku' => 'A']],
            'empty key field' => ['products', ['sku' => '']],
            'non scalar key field' => ['products', ['sku' => ['A']]],
        ];
    }

    public function testAFailingPublisherDoesNotStopTheOtherEventsNorThrow(): void
    {
        $collector = $this->collector();
        $this->inTransaction = true;
        $collector->record('products', EventVerb::Updated, ['sku' => 'A']);
        $collector->record('products', EventVerb::Updated, ['sku' => 'B']);
        $this->publisherFails = true;

        $collector->flush();
        $this->publisherFails = false;
        $collector->flush();

        self::assertSame([], $this->published, 'a failed flush is not replayed by the next commit');
    }

    private function collector(): Collector
    {
        $settings = $this->createMock(SettingsInterface::class);
        $settings->method('isEnabled')->willReturnCallback(fn () => $this->enabled);
        $transaction = $this->createMock(TransactionStateInterface::class);
        $transaction->method('inTransaction')->willReturnCallback(fn () => $this->inTransaction);
        $publisher = $this->createMock(EventPublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(function (string $lane, ConnectionEvent $event): void {
            if ($this->publisherFails) {
                throw new RuntimeException('down');
            }
            $this->published[] = [$lane, $event];
        });
        $ids = $this->createMock(IdGeneratorInterface::class);
        $counter = 0;
        $ids->method('next')->willReturnCallback(function () use (&$counter) {
            return 'id-' . ++$counter;
        });
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(1794571200);

        return new Collector(
            new StreamRegistry([
                'products' => ['lane' => 'bulk', 'key' => ['sku'], 'optional' => ['previous_sku']],
                'stock' => ['lane' => 'realtime', 'key' => ['sku', 'source_code']],
            ]),
            $settings,
            $transaction,
            $publisher,
            $ids,
            $clock,
            new NullLogger()
        );
    }
}
