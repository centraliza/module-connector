<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Product\Plugin;

use Centraliza\Connector\Entity\Product\ProductSkus;
use Centraliza\Connector\Entity\WebsiteScope;
use Magento\Catalog\Model\Product\Action;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The mass "update websites" action writes the link table directly and saves no product.
 */
class RecordWebsiteAssignment
{
    public function __construct(
        private readonly ProductSkus $productSkus,
        private readonly WebsiteScope $scope,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Record the products added to or removed from a reporting website.
     *
     * @param Action $subject
     * @param mixed $result
     * @param array<int|string> $productIds
     * @param array<int|string> $websiteIds
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterUpdateWebsites(Action $subject, $result, $productIds, $websiteIds)
    {
        try {
            if ($this->scope->coversAny((array)$websiteIds)) {
                // Not filtered by current assignment: a product just removed from the website is the point.
                $this->productSkus->recordUpdated((array)$productIds, false);
            }
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not record a website assignment.', ['exception' => $e]);
        }

        return $result;
    }
}
