<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Queue;

use Centraliza\Connector\Core\Model\ConnectionEvent;
use Centraliza\Connector\Core\Port\ClockInterface;
use Centraliza\Connector\Core\Port\EventPublisherInterface;
use Centraliza\Connector\Core\Port\RetryStoreInterface;
use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Producer-side circuit breaker. A broker that is down or slow must not hold
 * up the save that triggered the event: the event is parked in the retry
 * store and the cron publishes it later.
 */
class GuardedPublisher implements EventPublisherInterface
{
    private const CACHE_KEY = 'centraliza_connector_publisher_open';
    private const OPEN_SECONDS = 30;
    private const SLOW_SECONDS = 1.0;

    public function __construct(
        private readonly QueuePublisher $publisher,
        private readonly RetryStoreInterface $retryStore,
        private readonly CacheInterface $cache,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    public function publish(string $lane, ConnectionEvent $event, int $attempts = 0): void
    {
        if ($this->cache->load(self::CACHE_KEY)) {
            $this->park($lane, $event, $attempts, 'Queue publisher is paused.');

            return;
        }

        $startedAt = microtime(true);
        try {
            $this->publisher->publish($lane, $event, $attempts);
        } catch (Throwable $e) {
            $this->open('Queue publish failed: ' . get_class($e));
            $this->park($lane, $event, $attempts, 'Queue publish failed.');

            return;
        }

        if (microtime(true) - $startedAt > self::SLOW_SECONDS) {
            $this->open('Queue publish was slow.');
        }
    }

    private function park(string $lane, ConnectionEvent $event, int $attempts, string $reason): void
    {
        $this->retryStore->hold($event, $lane, $attempts, $this->clock->now(), $reason);
    }

    private function open(string $reason): void
    {
        $this->cache->save('1', self::CACHE_KEY, [], self::OPEN_SECONDS);
        $this->logger->warning('Centraliza Connector paused queue publishing for 30 seconds. ' . $reason);
    }
}
