<?php

declare(strict_types=1);

namespace Centraliza\Connector\Test\Unit\Core;

use Centraliza\Connector\Core\Model\ConnectionEvent;
use Centraliza\Connector\Core\Model\EventVerb;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ConnectionEventTest extends TestCase
{
    public function testBuildsTheBodyTheApiDocuments(): void
    {
        $event = new ConnectionEvent(
            'stock',
            EventVerb::Updated,
            ['sku' => 'LUVA-01', 'source_code' => 'default'],
            'idem-1',
            '2026-11-13T12:00:00Z'
        );

        self::assertSame('stock.updated', $event->type());
        self::assertSame(
            [
                'connection' => '0b6e63aa-0000-4000-8000-000000000001',
                'source' => 'magento',
                'type' => 'stock.updated',
                'idempotency_key' => 'idem-1',
                'occurred_at' => '2026-11-13T12:00:00Z',
                'data' => ['sku' => 'LUVA-01', 'source_code' => 'default'],
            ],
            $event->toRequestBody('0b6e63aa-0000-4000-8000-000000000001')
        );
    }

    public function testTheBodyNeverCarriesAContact(): void
    {
        $event = new ConnectionEvent('products', EventVerb::Deleted, ['sku' => 'A'], 'k', '2026-11-13T12:00:00Z');

        self::assertArrayNotHasKey('contact', $event->toRequestBody('c'));
    }

    /**
     * @dataProvider invalidEvents
     */
    public function testRefusesWhatTheApiWouldReject(string $stream, array $key, string $idempotencyKey): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConnectionEvent($stream, EventVerb::Updated, $key, $idempotencyKey, '2026-11-13T12:00:00Z');
    }

    public static function invalidEvents(): array
    {
        return [
            'stream with a dot' => ['products.x', ['sku' => 'A'], 'k'],
            'empty stream' => ['', ['sku' => 'A'], 'k'],
            'type over 96 characters' => [str_repeat('a', 90), ['sku' => 'A'], 'k'],
            'no key fields' => ['products', [], 'k'],
            'empty idempotency key' => ['products', ['sku' => 'A'], ''],
            'idempotency key over 191 characters' => ['products', ['sku' => 'A'], str_repeat('k', 192)],
        ];
    }
}
