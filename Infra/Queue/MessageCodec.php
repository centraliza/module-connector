<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Queue;

use Centraliza\Connector\Core\Model\ConnectionEvent;
use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Core\Model\HeldEvent;

/**
 * The queue message body. Everything travels in the body, because the DB
 * queue stores no message properties.
 */
class MessageCodec
{
    private const VERSION = 1;

    public function encode(string $lane, ConnectionEvent $event, int $attempts): string
    {
        return json_encode([
            'v' => self::VERSION,
            'id' => $event->idempotencyKey,
            'stream' => $event->stream,
            'verb' => $event->verb->value,
            'key' => $event->key,
            'at' => $event->occurredAt,
            'lane' => $lane,
            'attempts' => $attempts,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @throws \JsonException|\InvalidArgumentException|\ValueError|\TypeError when the body is not one of ours
     */
    public function decode(string $body): HeldEvent
    {
        $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);

        return new HeldEvent(
            new ConnectionEvent(
                (string)$data['stream'],
                EventVerb::from((string)$data['verb']),
                (array)$data['key'],
                (string)$data['id'],
                (string)$data['at']
            ),
            (string)$data['lane'],
            (int)$data['attempts']
        );
    }
}
