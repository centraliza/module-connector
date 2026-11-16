<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Location;

use Magento\Framework\App\ResourceConnection;

/**
 * An inventory source as stored: its own row, its carriers and the stocks it serves.
 */
class LocationSnapshot
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @param string[] $sourceCodes
     * @return array<string, string> stored state by source code, in a form that only serves comparison
     */
    public function forSources(array $sourceCodes): array
    {
        $sourceCodes = array_values(array_unique($sourceCodes));
        if ($sourceCodes === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $state = [];
        $sources = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('inventory_source'))
                ->where('source_code IN (?)', $sourceCodes)
        );
        foreach ($sources as $row) {
            $state[(string)$row['source_code']] = ['source' => $row, 'carriers' => [], 'stocks' => []];
        }

        $links = [
            'carriers' => ['inventory_source_carrier_link', ['source_code', 'carrier_code', 'position'], 'carrier_code'],
            'stocks' => ['inventory_source_stock_link', ['source_code', 'stock_id', 'priority'], 'stock_id'],
        ];
        foreach ($links as $part => [$table, $columns, $order]) {
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($this->resource->getTableName($table), $columns)
                    ->where('source_code IN (?)', $sourceCodes)
                    ->order($order)
            );
            foreach ($rows as $row) {
                $code = (string)$row['source_code'];
                unset($row['source_code']);
                if (isset($state[$code])) {
                    $state[$code][$part][] = $row;
                }
            }
        }

        return array_map(static fn (array $source): string => (string)json_encode($source), $state);
    }
}
