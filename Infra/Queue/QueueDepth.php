<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Queue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\MessageQueue\DefaultValueProvider;

/**
 * How many messages wait in each lane. Only the database queue can be
 * counted from here; with RabbitMQ the broker is the one to ask.
 */
class QueueDepth
{
    private const STATUS_NEW = 2;
    private const STATUS_RETRY_REQUIRED = 5;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DefaultValueProvider $defaults
    ) {
    }

    /**
     * @return array<string, int>|null waiting messages by lane; null when the queue is not the database one
     */
    public function byLane(): ?array
    {
        if ($this->defaults->getConnection() !== 'db') {
            return null;
        }
        $connection = $this->resource->getConnection();
        $counts = $connection->fetchPairs(
            $connection->select()
                ->from(['q' => $this->resource->getTableName('queue')], ['name'])
                ->join(['s' => $this->resource->getTableName('queue_message_status')], 's.queue_id = q.id', [])
                ->columns(['waiting' => 'COUNT(*)'])
                ->where('q.name LIKE ?', QueuePublisher::TOPIC_PREFIX . '%')
                ->where('s.status IN (?)', [self::STATUS_NEW, self::STATUS_RETRY_REQUIRED])
                ->group('q.name')
        );

        $byLane = [];
        foreach ($counts as $queue => $waiting) {
            $byLane[substr((string)$queue, strlen(QueuePublisher::TOPIC_PREFIX))] = (int)$waiting;
        }

        return $byLane;
    }
}
