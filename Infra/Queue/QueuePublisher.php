<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Queue;

use Centraliza\Connector\Core\Model\ConnectionEvent;
use Centraliza\Connector\Core\Port\EventPublisherInterface;
use Magento\Framework\MessageQueue\PublisherInterface;

/**
 * Publishes straight to the Magento queue and lets failures through.
 */
class QueuePublisher implements EventPublisherInterface
{
    public const TOPIC_PREFIX = 'centraliza.event.';

    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly MessageCodec $codec
    ) {
    }

    public function publish(string $lane, ConnectionEvent $event, int $attempts = 0): void
    {
        $this->publisher->publish(self::TOPIC_PREFIX . $lane, $this->codec->encode($lane, $event, $attempts));
    }
}
