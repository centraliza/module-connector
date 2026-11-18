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
    public const STATUS_NEW = 2;
    public const STATUS_IN_PROGRESS = 3;
    public const STATUS_COMPLETE = 4;
    public const STATUS_RETRY_REQUIRED = 5;

    /**
     * A lane whose oldest message waits longer than this, with nothing finished in the same time, has no consumer.
     */
    public const STALLED_AFTER_SECONDS = 300;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DefaultValueProvider $defaults
    ) {
    }

    public function isDatabaseQueue(): bool
    {
        return $this->defaults->getConnection() === 'db';
    }

    /**
     * @return array<string, int>|null waiting messages by lane; null when the queue is not the database one
     */
    public function byLane(): ?array
    {
        $lanes = $this->lanes();
        if ($lanes === null) {
            return null;
        }

        return array_map(static fn (array $lane): int => $lane['waiting'], $lanes);
    }

    /**
     * Each lane with messages waiting: how many, for how long the oldest has waited, and whether the lane moves.
     *
     * @return array<string, array{waiting: int, oldest_seconds: int, stalled: bool}>|null null when the queue is not
     *         the database one
     */
    public function lanes(): ?array
    {
        if (!$this->isDatabaseQueue()) {
            return null;
        }
        $connection = $this->resource->getConnection();
        $waiting = [self::STATUS_NEW, self::STATUS_RETRY_REQUIRED];
        // Both ages come from the clock of the database, the one that wrote updated_at.
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['q' => $this->resource->getTableName('queue')], ['name'])
                ->join(['s' => $this->resource->getTableName('queue_message_status')], 's.queue_id = q.id', [])
                ->columns([
                    'waiting' => new \Zend_Db_Expr(
                        'SUM(s.status IN (' . implode(',', $waiting) . '))'
                    ),
                    'oldest_seconds' => new \Zend_Db_Expr(
                        'TIMESTAMPDIFF(SECOND, MIN(IF(s.status IN (' . implode(',', $waiting) . '), s.updated_at, NULL)), NOW())'
                    ),
                    'finished_seconds' => new \Zend_Db_Expr(
                        'TIMESTAMPDIFF(SECOND, MAX(IF(s.status = ' . self::STATUS_COMPLETE . ', s.updated_at, NULL)), NOW())'
                    ),
                ])
                ->where('q.name LIKE ?', QueuePublisher::TOPIC_PREFIX . '%')
                ->where('s.status IN (?)', [...$waiting, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETE])
                ->group('q.name')
        );

        $lanes = [];
        foreach ($rows as $row) {
            $count = (int)$row['waiting'];
            if ($count === 0) {
                continue;
            }
            $oldest = max(0, (int)$row['oldest_seconds']);
            $finished = $row['finished_seconds'] === null ? null : (int)$row['finished_seconds'];
            $lanes[substr((string)$row['name'], strlen(QueuePublisher::TOPIC_PREFIX))] = [
                'waiting' => $count,
                'oldest_seconds' => $oldest,
                // A long backlog drains slowly but drains: something finished recently means a consumer is there.
                'stalled' => $oldest > self::STALLED_AFTER_SECONDS
                    && ($finished === null || $finished > self::STALLED_AFTER_SECONDS),
            ];
        }

        return $lanes;
    }
}
