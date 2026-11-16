<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Price\Plugin;

use Centraliza\Connector\Entity\Price\PriceRecorder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The price storage APIs (base, special and tier prices by SKU, the usual
 * route for an ERP) write the price tables directly and save no product.
 */
class RecordPriceStorage
{
    public function __construct(
        private readonly PriceRecorder $recorder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param object $subject
     * @param callable $proceed
     * @param array<int, mixed> $prices
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundUpdate(object $subject, callable $proceed, array $prices)
    {
        return $this->around($proceed, $prices);
    }

    /**
     * @param object $subject
     * @param callable $proceed
     * @param array<int, mixed> $prices
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundReplace(object $subject, callable $proceed, array $prices)
    {
        return $this->around($proceed, $prices);
    }

    /**
     * @param object $subject
     * @param callable $proceed
     * @param array<int, mixed> $prices
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundDelete(object $subject, callable $proceed, array $prices)
    {
        return $this->around($proceed, $prices);
    }

    /**
     * @param array<int, mixed> $prices
     */
    private function around(callable $proceed, array $prices): mixed
    {
        $skus = [];
        $before = null;
        try {
            foreach ($prices as $price) {
                if (is_object($price) && method_exists($price, 'getSku')) {
                    $skus[] = (string)$price->getSku();
                }
            }
            $skus = array_values(array_unique($skus));
            $before = $this->recorder->stored($skus);
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not read prices before a price write.', ['exception' => $e]);
        }

        $result = $proceed($prices);

        if ($before !== null) {
            try {
                $this->recorder->recordChanged($skus, $before);
            } catch (Throwable $e) {
                $this->logger->error('Centraliza Connector could not record a price write.', ['exception' => $e]);
            }
        }

        return $result;
    }
}
