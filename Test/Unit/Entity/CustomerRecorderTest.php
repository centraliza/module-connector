<?php

declare(strict_types=1);

namespace Centraliza\Connector\Test\Unit\Entity;

use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Core\Port\ChangeRecorderInterface;
use Centraliza\Connector\Entity\Customer\CustomerRecorder;
use Centraliza\Connector\Entity\Customer\CustomerSnapshot;
use Centraliza\Connector\Entity\WebsiteScope;
use PHPUnit\Framework\TestCase;

class CustomerRecorderTest extends TestCase
{
    /** @var array<int, array{0: string, 1: EventVerb, 2: array<string, scalar>}> */
    private array $recorded = [];

    /** @var array<int, array{email: string, website_id: int, state: string}|null> what the database holds, in reading order */
    private array $stored = [];

    public function testASaveThatChangesNothingReportsNothing(): void
    {
        $this->stored = [$this->state('a'), $this->state('a')];
        $recorder = $this->recorder();

        $recorder->remember(7);
        $recorder->compare(7);

        self::assertSame([], $this->recorded);
    }

    public function testAChangeToTheCustomerOrToAnAddressIsAnUpdate(): void
    {
        $this->stored = [$this->state('a'), $this->state('b')];
        $recorder = $this->recorder();

        $recorder->remember(7);
        $recorder->compare(7);

        self::assertSame([['customers', EventVerb::Updated, ['customer_id' => 7, 'email' => 'ana@example.com']]], $this->recorded);
    }

    public function testANewCustomerIsCreated(): void
    {
        $this->stored = [$this->state('a')];
        $recorder = $this->recorder();

        $recorder->remember(0);
        $recorder->created(7);

        self::assertSame(EventVerb::Created, $this->recorded[0][1]);
    }

    public function testAWriteNotSeenBeforehandIsReportedAsAnUpdate(): void
    {
        $this->stored = [$this->state('a')];

        $this->recorder()->compare(7);

        self::assertSame(EventVerb::Updated, $this->recorded[0][1]);
    }

    public function testADeleteReportsTheCustomerAsItWas(): void
    {
        $this->stored = [$this->state('a')];
        $recorder = $this->recorder();

        $recorder->remember(7);
        $recorder->deleted(7);

        self::assertSame([['customers', EventVerb::Deleted, ['customer_id' => 7, 'email' => 'ana@example.com']]], $this->recorded);
    }

    public function testACustomerOfAWebsiteThatDoesNotReportIsIgnored(): void
    {
        $this->stored = [$this->state('a', 2), $this->state('b', 2), $this->state('b', 2)];
        $recorder = $this->recorder();

        $recorder->remember(7);
        $recorder->compare(7);
        $recorder->remember(7);
        $recorder->deleted(7);

        self::assertSame([], $this->recorded);
    }

    /**
     * @return array{email: string, website_id: int, state: string}
     */
    private function state(string $state, int $websiteId = 1): array
    {
        return ['email' => 'ana@example.com', 'website_id' => $websiteId, 'state' => $state];
    }

    private function recorder(): CustomerRecorder
    {
        $snapshot = $this->createStub(CustomerSnapshot::class);
        $snapshot->method('of')->willReturnCallback(fn () => array_shift($this->stored));
        $scope = $this->createStub(WebsiteScope::class);
        $scope->method('coversAny')->willReturnCallback(static fn (array $ids): bool => in_array(1, $ids, true));
        $changes = $this->createStub(ChangeRecorderInterface::class);
        $changes->method('record')->willReturnCallback(function (string $stream, EventVerb $verb, array $key): void {
            $this->recorded[] = [$stream, $verb, $key];
        });

        return new CustomerRecorder($snapshot, $scope, $changes);
    }
}
