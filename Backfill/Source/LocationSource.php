<?php

declare(strict_types=1);

namespace Centraliza\Connector\Backfill\Source;

use Centraliza\Connector\Backfill\BackfillSourceInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Every inventory source: sources are global, and their code is their id.
 */
class LocationSource implements BackfillSourceInterface
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function count(int $websiteId): int
    {
        $connection = $this->resource->getConnection();

        return (int)$connection->fetchOne(
            $connection->select()->from($this->resource->getTableName('inventory_source'), ['total' => 'COUNT(*)'])
        );
    }

    public function next(int $websiteId, string $lastKey, int $limit): array
    {
        $connection = $this->resource->getConnection();
        $codes = $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('inventory_source'), ['source_code'])
                ->where('source_code > ?', $lastKey)
                ->order('source_code ASC')
                ->limit(max(1, $limit))
        );

        $keys = [];
        foreach ($codes as $code) {
            $keys[] = ['source_code' => (string)$code];
            $lastKey = (string)$code;
        }

        return [$keys, $lastKey];
    }
}
