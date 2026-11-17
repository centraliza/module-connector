<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Persistence;

use Centraliza\Connector\Core\Model\ConnectionEvent;
use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Core\Port\QuarantineStoreInterface;
use Magento\Framework\App\ResourceConnection;
use Throwable;

class QuarantineStore implements QuarantineStoreInterface
{
    private const TABLE = 'centraliza_connector_quarantine';
    private const MAX_REASON_LENGTH = 255;

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function quarantine(ConnectionEvent $event, string $lane, int $attempts, string $reason): void
    {
        $this->insert([
            'idempotency_key' => $event->idempotencyKey,
            'stream' => $event->stream,
            'verb' => $event->verb->value,
            'key_json' => json_encode($event->key, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'occurred_at' => $event->occurredAt,
            'lane' => $lane,
            'attempts' => $attempts,
        ], $reason);
    }

    /**
     * Quarantines a stored row as it is, for rows that can no longer be read as an event.
     *
     * @param array<string, mixed> $row with the event columns shared with the retry table
     */
    public function quarantineRow(array $row, string $reason): void
    {
        $this->insert([
            'idempotency_key' => (string)$row['idempotency_key'],
            'stream' => (string)$row['stream'],
            'verb' => (string)$row['verb'],
            'key_json' => (string)$row['key_json'],
            'occurred_at' => (string)$row['occurred_at'],
            'lane' => (string)$row['lane'],
            'attempts' => (int)$row['attempts'],
        ], $reason);
    }

    public function count(): int
    {
        $connection = $this->resource->getConnection();

        return (int)$connection->fetchOne(
            $connection->select()->from($this->table(), ['COUNT(*)'])
        );
    }

    /**
     * Oldest first.
     *
     * @param int[] $ids empty for any
     * @param int $afterId only rows quarantined after this one, to walk the table in pages
     * @return array<int, array<string, mixed>>
     */
    public function rows(int $limit, array $ids = [], int $afterId = 0): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->table())
            ->where('quarantine_id > ?', $afterId)
            ->order('quarantine_id ASC')
            ->limit($limit);
        if ($ids !== []) {
            $select->where('quarantine_id IN (?)', $ids);
        }

        return $connection->fetchAll($select);
    }

    /**
     * The stored row as the event it was, or null when it cannot be read back.
     *
     * @param array<string, mixed> $row
     */
    public function event(array $row): ?ConnectionEvent
    {
        try {
            return new ConnectionEvent(
                (string)$row['stream'],
                EventVerb::from((string)$row['verb']),
                json_decode((string)$row['key_json'], true, 8, JSON_THROW_ON_ERROR),
                (string)$row['idempotency_key'],
                (string)$row['occurred_at']
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param int[] $ids
     */
    public function remove(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return (int)$this->resource->getConnection()->delete($this->table(), ['quarantine_id IN (?)' => $ids]);
    }

    /**
     * @param array<string, mixed> $columns
     */
    private function insert(array $columns, string $reason): void
    {
        $columns['reason'] = mb_substr($reason, 0, self::MAX_REASON_LENGTH);
        $this->resource->getConnection()->insertOnDuplicate(
            $this->table(),
            $columns,
            ['lane', 'attempts', 'reason']
        );
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
