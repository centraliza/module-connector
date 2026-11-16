<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Product\Plugin;

use Centraliza\Connector\Entity\Price\PriceSnapshot;
use Centraliza\Connector\Entity\Product\ProductSkus;
use Magento\Catalog\Model\ResourceModel\Product\Action;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The mass "update attributes" action writes the attribute tables directly and saves no product.
 */
class RecordMassAttributeUpdate
{
    public function __construct(
        private readonly ProductSkus $productSkus,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Record every product the mass update touched.
     *
     * @param Action $subject
     * @param Action $result
     * @param array<int|string> $entityIds
     * @param array<string, mixed> $attrData
     * @return Action
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterUpdateAttributes(Action $subject, $result, $entityIds, $attrData = [])
    {
        try {
            $attributes = array_keys((array)$attrData);
            $priceAttributes = array_intersect($attributes, PriceSnapshot::FIELDS);
            if ($priceAttributes !== []) {
                $this->productSkus->recordUpdated((array)$entityIds, true, 'prices');
            }
            // With no attribute list to go by, the product is reported, as before.
            if ($attributes === [] || array_diff($attributes, PriceSnapshot::FIELDS) !== []) {
                $this->productSkus->recordUpdated((array)$entityIds);
            }
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not record a mass update.', ['exception' => $e]);
        }

        return $result;
    }
}
