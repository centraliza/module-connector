<?php

declare(strict_types=1);

namespace Centraliza\Connector\Test\Unit\Messaging;

use Centraliza\Connector\Messaging\Api\QuotaReservationInterface;
use Centraliza\Connector\Messaging\Model\Api\QuotaReservation;
use Centraliza\Connector\Messaging\Model\LegacyModules;
use Centraliza\Connector\Messaging\Model\ResourceModel\RateLimit;
use Centraliza\Connector\Infra\Config\Settings;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class QuotaReservationTest extends TestCase
{
    private const HASH = 'hash';
    private const ROUTE = 'admin/events';

    private RateLimit&MockObject $rateLimit;

    /** @var array<string, mixed> */
    private array $config = [];

    private bool $legacyLimiter = false;

    protected function setUp(): void
    {
        $this->rateLimit = $this->createMock(RateLimit::class);
    }

    /**
     * @dataProvider limits
     */
    public function testReadsTheLimitFromTheConfiguration(mixed $configured, bool $legacy, ?int $expected): void
    {
        $this->config[QuotaReservation::PATH_REQUEST_LIMIT] = $configured;
        $this->legacyLimiter = $legacy;

        self::assertSame($expected, $this->quota()->getLimit());
    }

    /**
     * @return array<string, array{0: mixed, 1: bool, 2: ?int}>
     */
    public static function limits(): array
    {
        return [
            'not set' => [null, false, 60],
            'empty' => ['', false, 60],
            'raised' => ['600', false, 600],
            'raised, legacy limiter still on' => ['600', true, 60],
            'lowered, legacy limiter still on' => ['40', true, 40],
            'off' => ['0', false, null],
            'off, legacy limiter still on' => ['0', true, null],
            'too low to keep the floors' => ['10', false, 60],
        ];
    }

    public function testTheConnectionEventsReserveUnderTheConfiguredLimit(): void
    {
        $this->config[QuotaReservation::PATH_REQUEST_LIMIT] = '600';
        $this->config[QuotaReservation::PATH_BULK_QUOTA_FLOOR] = '100';
        $this->rateLimit->expects(self::once())->method('reserve')
            ->with(self::HASH, 100, self::ROUTE, true, 600)
            ->willReturn(true);

        self::assertTrue($this->quota()->reserve(self::HASH, QuotaReservationInterface::PRIORITY_BULK, self::ROUTE));
    }

    /**
     * @dataProvider floors
     */
    public function testAFloorThatDoesNotFitTheLimitFallsBack(string $limit, string $floor, int $expected): void
    {
        $this->config[QuotaReservation::PATH_REQUEST_LIMIT] = $limit;
        $this->config[QuotaReservation::PATH_BULK_QUOTA_FLOOR] = $floor;
        $this->rateLimit->expects(self::once())->method('reserve')
            ->with(self::HASH, $expected, self::ROUTE, true, (int)$limit)
            ->willReturn(true);

        $this->quota()->reserve(self::HASH, QuotaReservationInterface::PRIORITY_BULK, self::ROUTE);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function floors(): array
    {
        return [
            'default floor' => ['60', '30', 30],
            'floor at the limit' => ['60', '60', 30],
            'floor under the marketing floor' => ['60', '15', 30],
            'limit lowered under the default floor' => ['25', '30', 24],
        ];
    }

    public function testTheMessageFloorsStayAbsolute(): void
    {
        $this->config[QuotaReservation::PATH_REQUEST_LIMIT] = '600';
        $this->rateLimit->expects(self::once())->method('reserve')
            ->with(self::HASH, 20, self::ROUTE, true, 600)
            ->willReturn(true);

        $this->quota()->reserve(self::HASH, QuotaReservationInterface::PRIORITY_MARKETING, self::ROUTE);
    }

    public function testWithTheLimitOffOnlyABlockOrARefusalHoldsARequest(): void
    {
        $this->config[QuotaReservation::PATH_REQUEST_LIMIT] = '0';
        $this->rateLimit->expects(self::never())->method('reserve');
        $this->rateLimit->method('isPaused')->with(self::HASH, self::ROUTE, true)->willReturnOnConsecutiveCalls(false, true);
        $this->rateLimit->expects(self::once())->method('getWaitSeconds')
            ->with(self::HASH, self::ROUTE, true, false)
            ->willReturn(40);
        $quota = $this->quota();

        self::assertTrue($quota->reserve(self::HASH, QuotaReservationInterface::PRIORITY_BULK, self::ROUTE));
        self::assertFalse($quota->reserve(self::HASH, QuotaReservationInterface::PRIORITY_BULK, self::ROUTE));
        self::assertSame(40, $quota->getWaitSeconds(self::HASH, QuotaReservationInterface::PRIORITY_BULK, self::ROUTE));
    }

    public function testWithTheLimitOffNothingIsCounted(): void
    {
        $this->config[QuotaReservation::PATH_REQUEST_LIMIT] = '0';
        $this->rateLimit->expects(self::never())->method('recordServerRemaining');
        $this->rateLimit->expects(self::never())->method('consume');
        $quota = $this->quota();

        $quota->recordServerRemaining(self::HASH, 5);
        $quota->consume(self::HASH, 2);
    }

    public function testTheServerCountIsReadAgainstTheConfiguredLimit(): void
    {
        $this->config[QuotaReservation::PATH_REQUEST_LIMIT] = '600';
        $this->rateLimit->expects(self::once())->method('recordServerRemaining')->with(self::HASH, 550, 600);

        $this->quota()->recordServerRemaining(self::HASH, 550);
    }

    public function testAnUnknownPriorityFailsWithTheLimitOff(): void
    {
        $this->config[QuotaReservation::PATH_REQUEST_LIMIT] = '0';

        $this->expectException(\InvalidArgumentException::class);
        $this->quota()->reserve(self::HASH, 'urgent', self::ROUTE);
    }

    private function quota(): QuotaReservation
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('get')->willReturnCallback(fn (string $path) => $this->config[$path] ?? null);
        $legacy = $this->createMock(LegacyModules::class);
        $legacy->method('limiterActive')->willReturnCallback(fn () => $this->legacyLimiter);

        return new QuotaReservation($this->rateLimit, $settings, new NullLogger(), $legacy);
    }
}
