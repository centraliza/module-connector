<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Persistence;

use Centraliza\Connector\Core\Model\ConnectionEvent;
use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Core\Model\HeldEvent;
use Centraliza\Connector\Core\Port\RetryStoreInterface;
use Magento\Framework\App\ResourceConnection;
use Throwable;

class RetryStore implements RetryStoreInterface
{
    private const TABLE = 'centraliza_connector_retry';
    private const MAX_ERROR_LENGTH = 255;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly QuarantineStore $quarantine
    ) {
    }

    public function hold(ConnectionEvent $event, string $lane, int $attempts, int $dueAt, string $lastError = ''): void
    {
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            [
                'idempotency_key' => $event->idempotencyKey,
                'stream' => $event->stream,
                'verb' => $event->verb->value,
                'key_json' => json_encode($event->key, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'occurred_at' => $event->occurredAt,
                'lane' => $lane,
                'attempts' => $attempts,
                'next_attempt_at' => $dueAt,
                'last_error' => mb_substr($lastError, 0, self::MAX_ERROR_LENGTH),
            ],
            ['lane', 'attempts', 'next_attempt_at', 'last_error']
        );
    }

    public function due(int $now, int $limit): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE))
                ->where('next_attempt_at <= ?', $now)
                ->order(['next_attempt_at ASC', 'retry_id ASC'])
                ->limit($limit)
        );

        $held = [];
        foreach ($rows as $row) {
            try {
                $held[] = new HeldEvent(
                    new ConnectionEvent(
                        (string)$row['stream'],
                        EventVerb::from((string)$row['verb']),
                        json_decode((string)$row['key_json'], true, 8, JSON_THROW_ON_ERROR),
                        (string)$row['idempotency_key'],
                        (string)$row['occurred_at']
                    ),
                    (string)$row['lane'],
                    (int)$row['attempts']
                );
            } catch (Throwable $e) {
                // A row that cannot be turned back into an event would be picked up forever.
                $this->quarantine->quarantineRow($row, 'Unreadable stored event.');
                $connection->delete(
                    $this->resource->getTableName(self::TABLE),
                    ['retry_id = ?' => (int)$row['retry_id']]
                );
            }
        }

        return $held;
    }

    /**
     * @return array{count: int, oldest: int|null} how many events are held and since when (unix seconds) the oldest is
     */
    public function summary(): array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from(
                $this->resource->getTableName(self::TABLE),
                ['held' => 'COUNT(*)', 'oldest' => 'UNIX_TIMESTAMP(MIN(created_at))']
            )
        );

        return [
            'count' => (int)($row['held'] ?? 0),
            'oldest' => isset($row['oldest']) ? (int)$row['oldest'] : null,
        ];
    }

    public function forget(array $idempotencyKeys): void
    {
        if ($idempotencyKeys === []) {
            return;
        }
        $this->resource->getConnection()->delete(
            $this->resource->getTableName(self::TABLE),
            ['idempotency_key IN (?)' => $idempotencyKeys]
        );
    }
}
