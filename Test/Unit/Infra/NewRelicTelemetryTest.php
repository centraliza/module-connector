<?php

declare(strict_types=1);

namespace Centraliza\Connector\Test\Unit\Infra;

use Centraliza\Connector\Infra\Config\Settings;
use Centraliza\Connector\Infra\Telemetry\NewRelicAgent;
use Centraliza\Connector\Infra\Telemetry\NewRelicTelemetry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class NewRelicTelemetryTest extends TestCase
{
    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    private array $recorded = [];

    public function testAnEventSaysWhichStoreItCameFromAndCarriesOnlyScalars(): void
    {
        $this->telemetry(true)->event('CentralizaDelivery', ['stream' => 'orders', 'sendMs' => null, 'attempts' => 0]);

        self::assertSame(
            [['CentralizaDelivery', ['stream' => 'orders', 'attempts' => 0, 'storeHost' => 'www.loja.example', 'mode' => 'live', 'environment' => 'staging']]],
            $this->recorded
        );
    }

    public function testWithoutTheAgentNothingIsRecorded(): void
    {
        $this->telemetry(false)->event('CentralizaDelivery', ['stream' => 'orders']);

        self::assertSame([], $this->recorded);
    }

    public function testAFailingAgentNeverReachesTheCaller(): void
    {
        $agent = $this->createStub(NewRelicAgent::class);
        $agent->method('isAvailable')->willReturn(true);
        $agent->method('recordCustomEvent')->willThrowException(new RuntimeException('agent is gone'));

        (new NewRelicTelemetry($agent, $this->settings()))->event('CentralizaHealth', ['held' => 1]);

        $this->addToAssertionCount(1);
    }

    private function telemetry(bool $available): NewRelicTelemetry
    {
        $agent = $this->createStub(NewRelicAgent::class);
        $agent->method('isAvailable')->willReturn($available);
        $agent->method('recordCustomEvent')->willReturnCallback(function (string $name, array $attributes): void {
            $this->recorded[] = [$name, $attributes];
        });

        return new NewRelicTelemetry($agent, $this->settings());
    }

    private function settings(): Settings
    {
        $settings = $this->createStub(Settings::class);
        $settings->method('reportingHost')->willReturn('www.loja.example');
        $settings->method('isLive')->willReturn(true);
        $settings->method('environment')->willReturn('staging');

        return $settings;
    }
}
