<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Product\Observer;

use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Core\Port\ChangeRecorderInterface;
use Centraliza\Connector\Core\Service\RecordDiff;
use Centraliza\Connector\Entity\Price\PriceSnapshot;
use Centraliza\Connector\Entity\WebsiteScope;
use Magento\Catalog\Model\Product;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class RecordSave implements ObserverInterface
{
    /** Fields of the product row that are content, besides its EAV attributes. */
    private const STATIC_FIELDS = ['attribute_set_id', 'type_id', 'website_ids', 'category_ids', 'tier_price'];

    /** Attributes every save rewrites, or that Magento derives from other data. */
    private const IGNORED = ['updated_at', 'created_at', 'quantity_and_stock_status', 'has_options', 'required_options'];

    private const UNORDERED = ['website_ids', 'category_ids'];

    /** What identifies a gallery image as content; the rest of each entry is form state. */
    private const IMAGE_FIELDS = ['file', 'label', 'position', 'disabled', 'media_type'];

    public function __construct(
        private readonly ChangeRecorderInterface $recorder,
        private readonly RecordDiff $diff,
        private readonly WebsiteScope $scope,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $product = $observer->getEvent()->getData('product');
            if ($product instanceof Product) {
                $this->record($product);
            }
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not record a product save.', ['exception' => $e]);
        }
    }

    private function record(Product $product): void
    {
        $original = (array)$product->getOrigData();
        $websiteIds = array_merge((array)$product->getWebsiteIds(), (array)($original['website_ids'] ?? []));
        if (!$this->scope->coversAny($websiteIds)) {
            return;
        }

        $sku = (string)$product->getSku();
        $previousSku = (string)($original['sku'] ?? '');

        if ($product->isObjectNew() || $previousSku === '') {
            $this->recorder->record('products', EventVerb::Created, ['sku' => $sku]);
            // Composite products are saved with no price of their own.
            if ($product->getData('price') !== null && $product->getData('price') !== '') {
                $this->recorder->record('prices', EventVerb::Created, ['sku' => $sku]);
            }

            return;
        }
        if ($previousSku !== $sku) {
            // One event for the rename: the record is the same, only its key changed.
            $this->recorder->record('products', EventVerb::Updated, ['sku' => $sku, 'previous_sku' => $previousSku]);

            return;
        }
        $changed = $this->diff->changedFields(
            $this->content($product, (array)$product->getData()),
            $this->content($product, $original),
            self::IGNORED,
            self::UNORDERED
        );
        // Prices are a record of their own: a save that only changes a price does not report the product.
        if (array_intersect($changed, PriceSnapshot::FIELDS) !== []) {
            $this->recorder->record('prices', EventVerb::Updated, ['sku' => $sku]);
        }
        if (array_diff($changed, PriceSnapshot::FIELDS) !== []
            || $product->getData('is_changed_websites')
            || $product->getData('is_changed_categories')
        ) {
            $this->recorder->record('products', EventVerb::Updated, ['sku' => $sku]);
        }
    }

    /**
     * Keeps only what is content of the product. A saved product model also
     * carries form state and derived values that differ on every save.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function content(Product $product, array $data): array
    {
        $fields = array_merge(array_keys($product->getAttributes()), self::STATIC_FIELDS);
        $content = array_intersect_key($data, array_flip($fields));

        foreach ($content as $field => $value) {
            // An image role with no image is stored as null and submitted as this marker.
            if ($value === 'no_selection') {
                $content[$field] = null;
            }
        }

        $images = [];
        foreach ((array)($data['media_gallery']['images'] ?? []) as $image) {
            if (is_array($image) && empty($image['removed'])) {
                $images[] = array_intersect_key($image, array_flip(self::IMAGE_FIELDS));
            }
        }
        $content['media_gallery'] = $images;

        return $content;
    }
}
