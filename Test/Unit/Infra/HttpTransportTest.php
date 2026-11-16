<?php

declare(strict_types=1);

namespace Centraliza\Connector\Test\Unit\Infra;

use Centraliza\Connector\Core\Model\ConnectionEvent;
use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Core\Model\OutcomeKind;
use Centraliza\Connector\Core\Port\ClockInterface;
use Centraliza\Connector\Infra\Config\Settings;
use Centraliza\Connector\Infra\Http\HttpTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class HttpTransportTest extends TestCase
{
    private const NOW = 1794571200;
    private const KEY = 'csk_secret';

    /** @var array<int, array<string, mixed>> */
    private array $sent = [];

    private string $apiKey = self::KEY;

    public function testSendsTheEventAsDocumented(): void
    {
        $outcome = $this->transport(new Response(200, [], '{"id":"evt_1"}'))->send($this->event());

        self::assertSame(OutcomeKind::Accepted, $outcome->kind);
        $request = $this->sent[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.example.test/v1/admin/events', (string)$request->getUri());
        self::assertSame('Bearer ' . self::KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame(
            $this->event()->toRequestBody('conn-1'),
            json_decode((string)$request->getBody(), true)
        );
    }

    /**
     * @dataProvider answers
     */
    public function testClassifiesTheAnswer(Response $response, OutcomeKind $kind, ?int $retryAfter): void
    {
        $outcome = $this->transport($response)->send($this->event());

        self::assertSame($kind, $outcome->kind);
        self::assertSame($retryAfter, $outcome->retryAfterSeconds);
    }

    /**
     * @return array<string, array{0: Response, 1: OutcomeKind, 2: ?int}>
     */
    public static function answers(): array
    {
        return [
            'validation' => [new Response(422, [], '{"message":"bad"}'), OutcomeKind::Rejected, null],
            'rate limit, seconds' => [new Response(429, ['Retry-After' => '17']), OutcomeKind::RateLimited, 17],
            'rate limit, date' => [
                new Response(429, ['Retry-After' => gmdate('D, d M Y H:i:s', self::NOW + 40) . ' GMT']),
                OutcomeKind::RateLimited,
                40,
            ],
            'rate limit, no header' => [new Response(429), OutcomeKind::RateLimited, null],
            'revoked key' => [new Response(401), OutcomeKind::Unauthorized, null],
            'forbidden' => [new Response(403), OutcomeKind::RouteRefused, null],
            'unknown connection' => [new Response(404), OutcomeKind::RouteRefused, null],
            'server error' => [new Response(500), OutcomeKind::Unavailable, null],
            'maintenance' => [new Response(503, ['Retry-After' => '120']), OutcomeKind::Unavailable, 120],
            'redirect' => [new Response(302, ['Location' => 'https://elsewhere.test']), OutcomeKind::Unavailable, null],
        ];
    }

    public function testAValidationAnswerKeepsItsMessage(): void
    {
        $outcome = $this->transport(new Response(422, [], '{"message":"data.sku is required","trace":"x"}'))
            ->send($this->event());

        self::assertStringContainsString('data.sku is required', $outcome->detail);
        self::assertStringNotContainsString('trace', $outcome->detail);
    }

    public function testAConnectionFailureIsUnavailableAndNeverQuotesTheKey(): void
    {
        $failure = new ConnectException(
            'cURL error 28 for Bearer ' . self::KEY,
            new Request('POST', 'https://api.example.test/v1/admin/events')
        );

        $outcome = $this->transport($failure)->send($this->event());

        self::assertSame(OutcomeKind::Unavailable, $outcome->kind);
        self::assertStringNotContainsString(self::KEY, $outcome->detail);
    }

    public function testWithoutAKeyNothingIsSent(): void
    {
        $this->apiKey = '';

        $outcome = $this->transport(new Response(200))->send($this->event());

        self::assertSame(OutcomeKind::Unauthorized, $outcome->kind);
        self::assertSame([], $this->sent);
    }

    private function event(): ConnectionEvent
    {
        return new ConnectionEvent('stock', EventVerb::Updated, ['sku' => 'A', 'source_code' => 'default'], 'idem-1', '2026-11-13T00:00:00Z');
    }

    private function transport(Response|\Throwable $answer): HttpTransport
    {
        $stack = HandlerStack::create(new MockHandler([$answer]));
        $stack->push(Middleware::history($this->sent));
        $settings = $this->createMock(Settings::class);
        $settings->method('apiKey')->willReturnCallback(fn () => $this->apiKey);
        $settings->method('connectionId')->willReturn('conn-1');
        $settings->method('baseUrl')->willReturn('https://api.example.test');
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(self::NOW);

        return new HttpTransport(new Client(['handler' => $stack]), $settings, $clock);
    }
}
