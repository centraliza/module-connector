<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Location;

use Centraliza\Connector\Entity\ServiceRecord;
use Centraliza\Connector\Entity\StreamReaderInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\SourceRepositoryInterface;

/**
 * An inventory source with its address, its contact, its carriers and the
 * stocks it supplies.
 */
class LocationReader implements StreamReaderInterface
{
    private const ADDRESS = ['country_id', 'region_id', 'region', 'city', 'street', 'postcode', 'latitude', 'longitude'];
    private const CONTACT = ['contact_name', 'email', 'phone', 'fax'];
    private const PLACED = ['source_code', 'name', 'enabled', 'description', 'use_default_carrier_config', 'carrier_links'];

    public function __construct(
        private readonly SourceRepositoryInterface $sources,
        private readonly ServiceRecord $serviceRecord,
        private readonly ResourceConnection $resource
    ) {
    }

    public function read(array $key): ?array
    {
        try {
            $source = $this->sources->get((string)$key['source_code']);
        } catch (NoSuchEntityException) {
            return null;
        }
        $rest = $this->serviceRecord->toArray($source, SourceRepositoryInterface::class, 'get');

        $connection = $this->resource->getConnection();
        $stocks = $connection->fetchAll(
            $connection->select()
                ->from(['l' => $this->resource->getTableName('inventory_source_stock_link')], ['stock_id', 'priority'])
                ->join(['s' => $this->resource->getTableName('inventory_stock')], 's.stock_id = l.stock_id', ['name'])
                ->where('l.source_code = ?', (string)$key['source_code'])
                ->order('l.stock_id ASC')
        );

        $record = [
            'source_code' => $rest['source_code'] ?? null,
            'name' => $rest['name'] ?? null,
            'enabled' => $rest['enabled'] ?? null,
            'description' => $rest['description'] ?? null,
            'address' => $this->pick($rest, self::ADDRESS),
            'contact' => $this->pick($rest, self::CONTACT),
            'carriers' => [
                'use_default_config' => $rest['use_default_carrier_config'] ?? null,
                'links' => $rest['carrier_links'] ?? [],
            ],
            'stocks' => array_map(static fn (array $stock): array => [
                'id' => (int)$stock['stock_id'],
                'name' => (string)$stock['name'],
                'priority' => (int)$stock['priority'],
            ], $stocks),
        ];

        // Whatever else the source has, such as what other modules attach to it.
        return $record + array_diff_key($rest, array_flip(array_merge(self::PLACED, self::ADDRESS, self::CONTACT)));
    }

    /**
     * @param array<string, mixed> $data
     * @param string[] $fields
     * @return array<string, mixed>
     */
    private function pick(array $data, array $fields): array
    {
        $picked = [];
        foreach ($fields as $field) {
            $picked[$field] = $data[$field] ?? null;
        }

        return $picked;
    }
}
