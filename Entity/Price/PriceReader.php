<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Price;

use Centraliza\Connector\Core\Port\SettingsInterface;
use Centraliza\Connector\Entity\Names;
use Centraliza\Connector\Entity\ServiceRecord;
use Centraliza\Connector\Entity\StreamReaderInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * The prices stored on the product: base, special with its dates, and the
 * ones per customer group. Catalog price rules are not part of it.
 */
class PriceReader implements StreamReaderInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ServiceRecord $serviceRecord,
        private readonly Names $names,
        private readonly SettingsInterface $settings
    ) {
    }

    public function read(array $key): ?array
    {
        try {
            // Forced reload: a long-running consumer must not answer from the repository cache.
            $product = $this->productRepository->get((string)$key['sku'], false, (int)$this->settings->reportingStoreId(), true);
        } catch (NoSuchEntityException) {
            return null;
        }
        $rest = $this->serviceRecord->toArray($product, ProductRepositoryInterface::class, 'get');
        $attributes = (array)($rest['custom_attributes'] ?? []);

        $tierPrices = [];
        foreach ((array)($rest['tier_prices'] ?? []) as $tierPrice) {
            $extension = (array)($tierPrice['extension_attributes'] ?? []);
            $tierPrices[] = [
                'customer_group' => $this->names->customerGroup((int)($tierPrice['customer_group_id'] ?? 0)),
                'website' => $this->names->website((int)($extension['website_id'] ?? 0)),
                'qty' => $tierPrice['qty'] ?? null,
                'value' => $tierPrice['value'] ?? null,
                'percentage_value' => $extension['percentage_value'] ?? null,
            ];
        }

        return [
            'sku' => $rest['sku'] ?? null,
            'product_id' => $rest['id'] ?? null,
            'type_id' => $rest['type_id'] ?? null,
            'price' => $rest['price'] ?? null,
            'special_price' => $attributes['special_price'] ?? null,
            'special_from_date' => $attributes['special_from_date'] ?? null,
            'special_to_date' => $attributes['special_to_date'] ?? null,
            'tier_prices' => $tierPrices,
        ];
    }
}
