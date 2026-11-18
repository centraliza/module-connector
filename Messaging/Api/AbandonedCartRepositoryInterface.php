<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api;

use Centraliza\Connector\Messaging\Api\Data\AbandonedCartInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

interface AbandonedCartRepositoryInterface
{
    /**
     * @param int $entityId
     * @return AbandonedCartInterface
     * @throws NoSuchEntityException
     */
    public function getById(int $entityId): AbandonedCartInterface;

    /**
     * Null when the cart was never selected.
     *
     * @param int $quoteId
     * @return AbandonedCartInterface|null
     */
    public function getByQuoteId(int $quoteId): ?AbandonedCartInterface;

    /**
     * @param AbandonedCartInterface $abandonedCart
     * @return AbandonedCartInterface
     * @throws CouldNotSaveException
     */
    public function save(AbandonedCartInterface $abandonedCart): AbandonedCartInterface;

    /**
     * @param AbandonedCartInterface $abandonedCart
     * @return void
     * @throws CouldNotDeleteException
     */
    public function delete(AbandonedCartInterface $abandonedCart): void;
}
