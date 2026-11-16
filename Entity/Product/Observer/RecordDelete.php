<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Product\Observer;

use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Core\Port\ChangeRecorderInterface;
use Centraliza\Connector\Entity\WebsiteScope;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Listens to both delete events: the websites and the stock of the product
 * are only readable before the delete, and the delete is only certain after it.
 */
class RecordDelete implements ObserverInterface
{
    private const WEBSITES_BEFORE_DELETE = 'centraliza_connector_website_ids';
    private const SOURCES_BEFORE_DELETE = 'centraliza_connector_source_codes';

    public function __construct(
        private readonly ChangeRecorderInterface $recorder,
        private readonly WebsiteScope $scope,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $product = $observer->getEvent()->getData('product');
            if (!$product instanceof Product) {
                return;
            }
            if ($observer->getEvent()->getName() === 'catalog_product_delete_before') {
                $product->setData(self::WEBSITES_BEFORE_DELETE, (array)$product->getWebsiteIds());
                $product->setData(self::SOURCES_BEFORE_DELETE, $this->sourceCodes((string)$product->getSku()));

                return;
            }
            if ($this->scope->coversAny((array)$product->getData(self::WEBSITES_BEFORE_DELETE))) {
                $this->recorder->record('products', EventVerb::Deleted, ['sku' => (string)$product->getSku()]);
                $this->recorder->record('prices', EventVerb::Deleted, ['sku' => (string)$product->getSku()]);
                // Magento removes the stock later, in a queue consumer that no longer knows the product.
                foreach ((array)$product->getData(self::SOURCES_BEFORE_DELETE) as $sourceCode) {
                    $this->recorder->record(
                        'stock',
                        EventVerb::Deleted,
                        ['sku' => (string)$product->getSku(), 'source_code' => (string)$sourceCode]
                    );
                }
            }
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not record a product delete.', ['exception' => $e]);
        }
    }

    /**
     * @return string[]
     */
    private function sourceCodes(string $sku): array
    {
        $connection = $this->resource->getConnection();

        return $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('inventory_source_item'), ['source_code'])
                ->where('sku = ?', $sku)
        );
    }
}
