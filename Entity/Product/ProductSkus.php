<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Product;

use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Core\Port\ChangeRecorderInterface;
use Centraliza\Connector\Entity\WebsiteScope;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;

/**
 * Mass actions hand over product ids; Centraliza knows products by SKU.
 */
class ProductSkus
{
    public function __construct(
        private readonly ProductResource $productResource,
        private readonly ChangeRecorderInterface $recorder,
        private readonly WebsiteScope $scope
    ) {
    }

    /**
     * @param array<int|string> $productIds
     * @param bool $onlyCovered false when the caller already knows a reporting website is involved
     * @param string $stream the record that changed: the product itself, or its prices
     */
    public function recordUpdated(array $productIds, bool $onlyCovered = true, string $stream = 'products'): void
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($onlyCovered) {
            $productIds = $this->scope->coveredProductIds($productIds);
        }
        if ($productIds === []) {
            return;
        }
        foreach ($this->productResource->getProductsSku($productIds) as $row) {
            $this->recorder->record($stream, EventVerb::Updated, ['sku' => (string)$row['sku']]);
        }
    }
}
