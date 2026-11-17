<?php

declare(strict_types=1);

namespace Centraliza\Connector\Backfill\Source;

use Centraliza\Connector\Backfill\BackfillSourceInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;

/**
 * Pages through a table by its numeric id.
 */
abstract class IdPagedSource implements BackfillSourceInterface
{
    public function __construct(
        protected readonly ResourceConnection $resource
    ) {
    }

    /**
     * The rows to send, with the paging id selected as "page_id"; no order and no limit.
     */
    abstract protected function select(int $websiteId): Select;

    /** The column the rows are paged by, as the select names it. */
    abstract protected function idColumn(): string;

    /**
     * @param array<string, mixed> $row
     * @return array<string, scalar>
     */
    abstract protected function key(array $row): array;

    public function count(int $websiteId): int
    {
        $connection = $this->connection();

        return (int)$connection->fetchOne(
            $connection->select()->from(['source_rows' => $this->select($websiteId)], ['total' => 'COUNT(*)'])
        );
    }

    public function next(int $websiteId, string $lastKey, int $limit): array
    {
        // -1 so a record with id 0 (customer group NOT LOGGED IN) is sent too.
        $after = $lastKey === '' ? -1 : (int)$lastKey;
        $rows = $this->connection()->fetchAll(
            $this->select($websiteId)
                ->where($this->idColumn() . ' > ?', $after)
                ->order($this->idColumn() . ' ASC')
                ->limit(max(1, $limit))
        );

        $keys = [];
        foreach ($rows as $row) {
            $keys[] = $this->key($row);
            $lastKey = (string)$row['page_id'];
        }

        return [$keys, $lastKey];
    }

    protected function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    protected function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }

    /**
     * @return int[]
     */
    protected function storeIds(int $websiteId): array
    {
        $connection = $this->connection();

        return array_map('intval', $connection->fetchCol(
            $connection->select()->from($this->table('store'), ['store_id'])->where('website_id = ?', $websiteId)
        ));
    }
}
