<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Product;

use Centraliza\Connector\Core\Port\SettingsInterface;
use Centraliza\Connector\Entity\ServiceRecord;
use Centraliza\Connector\Entity\StreamReaderInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * The product with everything GET /V1/products/:sku answers, in the store view
 * that reports, arranged by ProductRecord.
 */
class ProductReader implements StreamReaderInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ServiceRecord $serviceRecord,
        private readonly ProductRecord $productRecord,
        private readonly SettingsInterface $settings
    ) {
    }

    public function read(array $key): ?array
    {
        $storeId = (int)$this->settings->reportingStoreId();
        try {
            // Forced reload: a long-running consumer must not answer from the repository cache.
            $product = $this->productRepository->get((string)$key['sku'], false, $storeId, true);
        } catch (NoSuchEntityException) {
            return null;
        }

        return $this->productRecord->arrange(
            $this->serviceRecord->toArray($product, ProductRepositoryInterface::class, 'get'),
            $storeId
        );
    }
}
