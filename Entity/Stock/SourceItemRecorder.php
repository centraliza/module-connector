<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Stock;

use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Core\Port\ChangeRecorderInterface;
use Centraliza\Connector\Core\Service\SnapshotDiff;
use Centraliza\Connector\Entity\WebsiteScope;
use Magento\Framework\App\ResourceConnection;
use Magento\InventoryApi\Api\Data\SourceItemInterface;

class SourceItemRecorder
{
    public function __construct(
        private readonly ChangeRecorderInterface $recorder,
        private readonly WebsiteScope $scope,
        private readonly ResourceConnection $resource,
        private readonly SnapshotDiff $diff
    ) {
    }

    /**
     * @param array<int, mixed> $sourceItems
     * @param array<string, array{0: float, 1: int}>|null $before quantity and status as stored before the write;
     *        when given, items that did not change are skipped
     */
    public function record(EventVerb $verb, array $sourceItems, ?array $before = null): void
    {
        $sourceItems = array_filter($sourceItems, static fn ($item) => $item instanceof SourceItemInterface);
        $covered = $this->scope->coveredSkus(
            array_values(array_unique(array_map(static fn ($item) => (string)$item->getSku(), $sourceItems)))
        );

        foreach ($sourceItems as $sourceItem) {
            $sku = (string)$sourceItem->getSku();
            $sourceCode = (string)$sourceItem->getSourceCode();
            if (!isset($covered[$sku])) {
                continue;
            }
            $stored = $before[$this->id($sku, $sourceCode)] ?? null;
            if ($before !== null && $stored !== null
                && $stored[0] === (float)$sourceItem->getQuantity()
                && $stored[1] === (int)$sourceItem->getStatus()
            ) {
                continue;
            }
            $this->recorder->record('stock', $verb, ['sku' => $sku, 'source_code' => $sourceCode]);
        }
    }

    /**
     * @param array<int, mixed> $sourceItems
     * @return array<string, array{0: float, 1: int}>
     */
    public function stored(array $sourceItems): array
    {
        $skus = [];
        foreach ($sourceItems as $sourceItem) {
            if ($sourceItem instanceof SourceItemInterface) {
                $skus[] = (string)$sourceItem->getSku();
            }
        }

        return $this->storedForSkus($skus);
    }

    /**
     * @param string[] $skus
     * @return array<string, array{0: float, 1: int}> quantity and status of every source item of these SKUs
     */
    public function storedForSkus(array $skus): array
    {
        if ($skus === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('inventory_source_item'), ['sku', 'source_code', 'quantity', 'status'])
                ->where('sku IN (?)', array_values(array_unique($skus)))
        );

        $stored = [];
        foreach ($rows as $row) {
            $stored[$this->id((string)$row['sku'], (string)$row['source_code'])] = [(float)$row['quantity'], (int)$row['status']];
        }

        return $stored;
    }

    /**
     * Reports what a bulk operation did, from the source items as they were before and after it.
     *
     * @param array<string, array{0: float, 1: int}> $before
     * @param array<string, array{0: float, 1: int}> $after
     */
    public function recordDifference(array $before, array $after): void
    {
        $changes = $this->diff->verbs($before, $after);
        if ($changes === []) {
            return;
        }

        $pairs = [];
        foreach (array_keys($changes) as $id) {
            $pairs[$id] = explode("\x1f", (string)$id, 2);
        }
        $covered = $this->scope->coveredSkus(array_values(array_unique(array_column($pairs, 0))));
        foreach ($changes as $id => $verb) {
            [$sku, $sourceCode] = $pairs[$id];
            if (isset($covered[$sku])) {
                $this->recorder->record('stock', $verb, ['sku' => $sku, 'source_code' => $sourceCode]);
            }
        }
    }

    private function id(string $sku, string $sourceCode): string
    {
        return $sku . "\x1f" . $sourceCode;
    }
}
