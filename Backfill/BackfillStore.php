<?php

declare(strict_types=1);

namespace Centraliza\Connector\Backfill;

use Magento\Framework\App\ResourceConnection;

/**
 * The sends of whole streams asked from the admin, and how far each one got.
 */
class BackfillStore
{
    public const TABLE = 'centraliza_connector_backfill';

    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const CANCELLED = 'cancelled';
    public const FAILED = 'failed';

    private const ACTIVE = [self::PENDING, self::RUNNING];
    private const MAX_ERROR_LENGTH = 255;

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function create(string $stream, int $websiteId, int $total, string $requestedBy): int
    {
        $connection = $this->resource->getConnection();
        if (!$connection instanceof \Zend_Db_Adapter_Abstract) {
            throw new \RuntimeException('The database adapter cannot tell the id of a new row.');
        }
        $connection->insert($this->table(), [
            'stream' => $stream,
            'website_id' => $websiteId,
            'status' => self::PENDING,
            'total' => $total,
            'requested_by' => mb_substr($requestedBy, 0, 64),
        ]);

        return (int)$connection->lastInsertId($this->table());
    }

    /**
     * Pending and running sends, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function active(?string $stream = null): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->table())
            ->where('status IN (?)', self::ACTIVE)
            ->order('backfill_id ASC');
        if ($stream !== null) {
            $select->where('stream = ?', $stream);
        }

        return array_values($connection->fetchAll($select));
    }

    /**
     * @return list<array<string, mixed>> newest first
     */
    public function recent(int $limit): array
    {
        $connection = $this->resource->getConnection();

        return array_values($connection->fetchAll(
            $connection->select()->from($this->table())->order('backfill_id DESC')->limit($limit)
        ));
    }

    /**
     * Records that another page went on the queue.
     */
    public function advance(int $backfillId, string $lastKey, int $published): void
    {
        $connection = $this->resource->getConnection();
        $connection->update(
            $this->table(),
            [
                'status' => self::RUNNING,
                'last_key' => $lastKey,
                'published' => new \Zend_Db_Expr($connection->quoteInto('published + ?', $published)),
            ],
            ['backfill_id = ?' => $backfillId, 'status IN (?)' => self::ACTIVE]
        );
    }

    /**
     * Ends an active send; one already ended is left as it is.
     *
     * @return bool whether it was active
     */
    public function finish(int $backfillId, string $status, string $error = ''): bool
    {
        return $this->resource->getConnection()->update(
            $this->table(),
            [
                'status' => $status,
                'last_error' => mb_substr($error, 0, self::MAX_ERROR_LENGTH),
                'finished_at' => new \Zend_Db_Expr('NOW()'),
            ],
            ['backfill_id = ?' => $backfillId, 'status IN (?)' => self::ACTIVE]
        ) > 0;
    }

    public function fail(int $backfillId, string $error): void
    {
        $this->resource->getConnection()->update(
            $this->table(),
            ['last_error' => mb_substr($error, 0, self::MAX_ERROR_LENGTH)],
            ['backfill_id = ?' => $backfillId]
        );
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
