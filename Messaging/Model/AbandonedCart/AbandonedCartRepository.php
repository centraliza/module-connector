<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\AbandonedCart;

use Centraliza\Connector\Messaging\Api\AbandonedCartRepositoryInterface;
use Centraliza\Connector\Messaging\Api\Data\AbandonedCartInterface;
use Centraliza\Connector\Messaging\Model\ResourceModel\AbandonedCart as AbandonedCartResource;
use Exception;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Model\AbstractModel;
use Psr\Log\LoggerInterface;

class AbandonedCartRepository implements AbandonedCartRepositoryInterface
{
    /**
     * @param AbandonedCartResource $resource
     * @param AbandonedCartFactory $abandonedCartFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AbandonedCartResource $resource,
        private readonly AbandonedCartFactory $abandonedCartFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param int $entityId
     * @return AbandonedCartInterface
     * @throws NoSuchEntityException
     */
    public function getById(int $entityId): AbandonedCartInterface
    {
        $abandonedCart = $this->abandonedCartFactory->create();
        $this->resource->load($abandonedCart, $entityId);

        if (!$abandonedCart->getId()) {
            throw new NoSuchEntityException(
                __('The abandoned cart with ID "%1" does not exist.', $entityId)
            );
        }

        return $abandonedCart;
    }

    /**
     * @param int $quoteId
     * @return AbandonedCartInterface|null
     */
    public function getByQuoteId(int $quoteId): ?AbandonedCartInterface
    {
        $abandonedCart = $this->abandonedCartFactory->create();
        $this->resource->load($abandonedCart, $quoteId, AbandonedCartInterface::QUOTE_ID);

        if (!$abandonedCart->getId()) {
            return null;
        }

        return $abandonedCart;
    }

    /**
     * @param AbandonedCartInterface $abandonedCart
     * @return AbandonedCartInterface
     * @throws CouldNotSaveException
     */
    public function save(AbandonedCartInterface $abandonedCart): AbandonedCartInterface
    {
        if (!$abandonedCart instanceof AbstractModel) {
            throw new CouldNotSaveException(__('The abandoned cart could not be saved.'));
        }

        try {
            $this->resource->save($abandonedCart);
        } catch (Exception $exception) {
            $this->logger->error(
                'Centraliza abandoned cart could not be saved',
                ['cart_id' => $abandonedCart->getQuoteId(), 'exception_class' => $exception::class]
            );

            throw new CouldNotSaveException(__('The abandoned cart could not be saved.'), $exception);
        }

        return $abandonedCart;
    }

    /**
     * @param AbandonedCartInterface $abandonedCart
     * @return void
     * @throws CouldNotDeleteException
     */
    public function delete(AbandonedCartInterface $abandonedCart): void
    {
        if (!$abandonedCart instanceof AbstractModel) {
            throw new CouldNotDeleteException(__('The abandoned cart could not be deleted.'));
        }

        try {
            $this->resource->delete($abandonedCart);
        } catch (Exception $exception) {
            $this->logger->error(
                'Centraliza abandoned cart could not be deleted',
                ['cart_id' => $abandonedCart->getQuoteId(), 'exception_class' => $exception::class]
            );

            throw new CouldNotDeleteException(__('The abandoned cart could not be deleted.'), $exception);
        }
    }
}
