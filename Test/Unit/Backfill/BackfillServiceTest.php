<?php

declare(strict_types=1);

namespace Centraliza\Connector\Test\Unit\Backfill;

use Centraliza\Connector\Backfill\BackfillService;
use Centraliza\Connector\Backfill\BackfillSourceInterface;
use Centraliza\Connector\Backfill\BackfillStore;
use Centraliza\Connector\Core\Model\ConnectionEvent;
use Centraliza\Connector\Core\Port\ClockInterface;
use Centraliza\Connector\Core\Port\EventPublisherInterface;
use Centraliza\Connector\Core\Port\IdGeneratorInterface;
use Centraliza\Connector\Core\Stream\StreamRegistry;
use Centraliza\Connector\Infra\Config\Settings;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class BackfillServiceTest extends TestCase
{
    private const WEBSITE = 1;

    /** @var list<array{0: string, 1: ConnectionEvent}> */
    private array $published = [];

    /** @var list<array<string, mixed>> what the store holds as active */
    private array $active = [];

    /** @var list<array{0: int, 1: string, 2: int}> */
    private array $advanced = [];

    /** @var list<array{0: int, 1: string, 2: string}> */
    private array $finished = [];

    private ?int $reportingWebsite = self::WEBSITE;

    private bool $lockFree = true;

    /** @var list<array<string, scalar>> the records of the fake source */
    private array $records = [];

    public function testAStreamWithoutASourceCannotBeAsked(): void
    {
        $this->expectException(LocalizedException::class);
        $this->service()->request('wishlists', 'admin');
    }

    public function testNothingIsAskedWhileNoWebsiteReports(): void
    {
        $this->reportingWebsite = null;

        $this->expectException(LocalizedException::class);
        $this->service()->request('products', 'admin');
    }

    public function testOneSendPerStreamAtATime(): void
    {
        $this->active = [$this->backfill()];

        $this->expectException(LocalizedException::class);
        $this->service()->request('products', 'admin');
    }

    public function testRunPutsEveryRecordOnTheBulkLaneAndEnds(): void
    {
        $this->records = [['sku' => 'A'], ['sku' => 'B'], ['sku' => 'C']];
        $this->active = [$this->backfill()];

        $published = $this->service()->run(30, 100);

        self::assertSame(3, $published);
        self::assertSame(['bulk', 'bulk', 'bulk'], array_column($this->published, 0));
        self::assertSame(['products.updated'], array_values(array_unique(array_map(fn ($p) => $p[1]->type(), $this->published))));
        self::assertSame([['sku' => 'A'], ['sku' => 'B'], ['sku' => 'C']], array_map(fn ($p) => $p[1]->key, $this->published));
        self::assertSame([[7, '3', 3]], $this->advanced);
        self::assertSame([[7, BackfillStore::DONE, '']], $this->finished);
    }

    public function testRunStopsAtTheRecordBudgetAndResumesWhereItStopped(): void
    {
        $this->records = [['sku' => 'A'], ['sku' => 'B'], ['sku' => 'C']];
        $this->active = [$this->backfill()];

        self::assertSame(2, $this->service()->run(30, 2));
        self::assertSame([], $this->finished);

        $this->active = [$this->backfill('2')];
        self::assertSame(1, $this->service()->run(30, 2));
        self::assertSame(['A', 'B', 'C'], array_map(fn ($p) => $p[1]->key['sku'], $this->published));
    }

    public function testARecordWithoutAUsableKeyIsSkipped(): void
    {
        $this->records = [['sku' => 'A'], ['sku' => ''], ['sku' => 'C']];
        $this->active = [$this->backfill()];

        $this->service()->run(30, 100);

        self::assertSame(['A', 'C'], array_map(fn ($p) => $p[1]->key['sku'], $this->published));
    }

    public function testASendForAnotherWebsiteFails(): void
    {
        $this->records = [['sku' => 'A']];
        $this->active = [$this->backfill()];
        $this->reportingWebsite = 2;

        self::assertSame(0, $this->service()->run(30, 100));
        self::assertSame(BackfillStore::FAILED, $this->finished[0][1]);
        self::assertSame([], $this->published);
    }

    public function testAnotherRunningProcessKeepsThisOneOut(): void
    {
        $this->records = [['sku' => 'A']];
        $this->active = [$this->backfill()];
        $this->lockFree = false;

        self::assertSame(0, $this->service()->run(30, 100));
        self::assertSame([], $this->published);
    }

    /**
     * @return array<string, mixed>
     */
    private function backfill(string $lastKey = ''): array
    {
        return ['backfill_id' => 7, 'stream' => 'products', 'website_id' => self::WEBSITE, 'last_key' => $lastKey];
    }

    private function service(): BackfillService
    {
        $store = $this->createMock(BackfillStore::class);
        $store->method('active')->willReturnCallback(fn (?string $stream = null) => $this->active);
        $store->method('advance')->willReturnCallback(function (int $id, string $lastKey, int $count): void {
            $this->advanced[] = [$id, $lastKey, $count];
        });
        $store->method('finish')->willReturnCallback(function (int $id, string $status, string $error = ''): bool {
            $this->finished[] = [$id, $status, $error];

            return true;
        });
        $store->method('create')->willReturn(9);

        $settings = $this->createMock(Settings::class);
        $settings->method('reportingWebsiteId')->willReturnCallback(fn () => $this->reportingWebsite);

        $publisher = $this->createMock(EventPublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(function (string $lane, ConnectionEvent $event): void {
            $this->published[] = [$lane, $event];
        });

        $ids = $this->createMock(IdGeneratorInterface::class);
        $ids->method('next')->willReturnCallback(fn () => uniqid('id-', true));
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(1000);
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->method('lock')->willReturnCallback(fn () => $this->lockFree);

        $source = new class ($this) implements BackfillSourceInterface {
            public function __construct(private readonly BackfillServiceTest $test)
            {
            }

            public function count(int $websiteId): int
            {
                return count($this->test->records());
            }

            public function next(int $websiteId, string $lastKey, int $limit): array
            {
                $page = array_slice($this->test->records(), (int)$lastKey, $limit);

                return [$page, (string)((int)$lastKey + count($page))];
            }
        };

        return new BackfillService(
            $store,
            $settings,
            new StreamRegistry(['products' => ['lane' => 'bulk', 'key' => ['sku']]]),
            $publisher,
            $ids,
            $clock,
            $locks,
            new NullLogger(),
            ['products' => $source]
        );
    }

    /**
     * @return list<array<string, scalar>>
     */
    public function records(): array
    {
        return $this->records;
    }
}
