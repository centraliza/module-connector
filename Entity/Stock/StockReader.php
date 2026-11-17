<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Stock;

use Centraliza\Connector\Entity\ServiceRecord;
use Centraliza\Connector\Entity\StreamReaderInterface;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\SourceItemRepositoryInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Throwable;

/**
 * What one source holds of one SKU, and what that leaves salable in each
 * stock the source supplies.
 */
class StockReader implements StreamReaderInterface
{
    public function __construct(
        private readonly SourceItemRepositoryInterface $sourceItems,
        private readonly SearchCriteriaBuilderFactory $criteriaFactory,
        private readonly ServiceRecord $serviceRecord,
        private readonly GetProductSalableQtyInterface $salableQty,
        private readonly ResourceConnection $resource
    ) {
    }

    public function read(array $key): ?array
    {
        $sku = (string)$key['sku'];
        $sourceCode = (string)$key['source_code'];
        $criteria = $this->criteriaFactory->create()
            ->addFilter(SourceItemInterface::SKU, $sku)
            ->addFilter(SourceItemInterface::SOURCE_CODE, $sourceCode)
            ->create();
        $found = $this->serviceRecord->toArray(
            $this->sourceItems->getList($criteria),
            SourceItemRepositoryInterface::class,
            'getList'
        );
        $item = $found['items'][0] ?? null;
        if (!is_array($item)) {
            return null;
        }

        $connection = $this->resource->getConnection();
        $source = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName('inventory_source'), ['name', 'enabled'])
                ->where('source_code = ?', $sourceCode)
        ) ?: [];
        $stocks = $connection->fetchAll(
            $connection->select()
                ->from(['l' => $this->resource->getTableName('inventory_source_stock_link')], ['stock_id'])
                ->join(['s' => $this->resource->getTableName('inventory_stock')], 's.stock_id = l.stock_id', ['name'])
                ->where('l.source_code = ?', $sourceCode)
                ->order('l.stock_id ASC')
        );

        $record = [
            'sku' => $sku,
            'source' => [
                'code' => $sourceCode,
                'name' => isset($source['name']) ? (string)$source['name'] : null,
                'enabled' => isset($source['enabled']) ? (bool)$source['enabled'] : null,
            ],
            'quantity' => $item['quantity'] ?? null,
            'status' => $item['status'] ?? null,
            'is_in_stock' => (int)($item['status'] ?? 0) === SourceItemInterface::STATUS_IN_STOCK,
            'salable' => array_map(fn (array $stock): array => [
                'stock' => ['id' => (int)$stock['stock_id'], 'name' => (string)$stock['name']],
                'qty' => $this->salable($sku, (int)$stock['stock_id']),
            ], $stocks),
        ];

        // Whatever else other modules attach to the source item.
        return $record + array_diff_key($item, array_flip(['sku', 'source_code', 'quantity', 'status']));
    }

    /**
     * Null for products Magento keeps no salable quantity for, such as composite ones.
     */
    private function salable(string $sku, int $stockId): ?float
    {
        try {
            return $this->salableQty->execute($sku, $stockId);
        } catch (Throwable) {
            return null;
        }
    }
}
