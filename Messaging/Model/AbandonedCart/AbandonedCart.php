<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\AbandonedCart;

use Centraliza\Connector\Messaging\Api\Data\AbandonedCartInterface;
use Centraliza\Connector\Messaging\Model\ResourceModel\AbandonedCart as AbandonedCartResource;
use Magento\Framework\Model\AbstractModel;

class AbandonedCart extends AbstractModel implements AbandonedCartInterface
{
    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(AbandonedCartResource::class);
    }

    /**
     * @return int|null
     */
    public function getEntityId()
    {
        return $this->getData(self::ENTITY_ID);
    }

    /**
     * @param int $entityId
     * @return $this
     */
    public function setEntityId($entityId)
    {
        return $this->setData(self::ENTITY_ID, $entityId);
    }

    /**
     * @return int
     */
    public function getQuoteId(): int
    {
        return (int) $this->getData(self::QUOTE_ID);
    }

    /**
     * @param int $quoteId
     * @return $this
     */
    public function setQuoteId(int $quoteId): self
    {
        return $this->setData(self::QUOTE_ID, $quoteId);
    }

    /**
     * @return int
     */
    public function getStoreId(): int
    {
        return (int) $this->getData(self::STORE_ID);
    }

    /**
     * @param int $storeId
     * @return $this
     */
    public function setStoreId(int $storeId): self
    {
        return $this->setData(self::STORE_ID, $storeId);
    }

    /**
     * @return int
     */
    public function getWebsiteId(): int
    {
        return (int) $this->getData(self::WEBSITE_ID);
    }

    /**
     * @param int $websiteId
     * @return $this
     */
    public function setWebsiteId(int $websiteId): self
    {
        return $this->setData(self::WEBSITE_ID, $websiteId);
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return (string) $this->getData(self::STATUS);
    }

    /**
     * @param string $status
     * @return $this
     */
    public function setStatus(string $status): self
    {
        return $this->setData(self::STATUS, $status);
    }

    /**
     * @return int
     */
    public function getAttempts(): int
    {
        return (int) $this->getData(self::ATTEMPTS);
    }

    /**
     * @param int $attempts
     * @return $this
     */
    public function setAttempts(int $attempts): self
    {
        return $this->setData(self::ATTEMPTS, $attempts);
    }

    /**
     * @return string|null
     */
    public function getNextAttemptAt(): ?string
    {
        return $this->getData(self::NEXT_ATTEMPT_AT);
    }

    /**
     * @param string|null $nextAttemptAt
     * @return $this
     */
    public function setNextAttemptAt(?string $nextAttemptAt): self
    {
        return $this->setData(self::NEXT_ATTEMPT_AT, $nextAttemptAt);
    }

    /**
     * @return int|null
     */
    public function getLastHttpStatus(): ?int
    {
        if (empty($this->getData(self::LAST_HTTP_STATUS))) {
            return null;
        }

        return (int) $this->getData(self::LAST_HTTP_STATUS);
    }

    /**
     * @param int|null $lastHttpStatus
     * @return $this
     */
    public function setLastHttpStatus(?int $lastHttpStatus): self
    {
        return $this->setData(self::LAST_HTTP_STATUS, $lastHttpStatus);
    }

    /**
     * @return string|null
     */
    public function getLastError(): ?string
    {
        return $this->getData(self::LAST_ERROR);
    }

    /**
     * @param string|null $lastError
     * @return $this
     */
    public function setLastError(?string $lastError): self
    {
        return $this->setData(self::LAST_ERROR, $lastError);
    }

    /**
     * @return string|null
     */
    public function getLastAttemptAt(): ?string
    {
        return $this->getData(self::LAST_ATTEMPT_AT);
    }

    /**
     * @param string|null $lastAttemptAt
     * @return $this
     */
    public function setLastAttemptAt(?string $lastAttemptAt): self
    {
        return $this->setData(self::LAST_ATTEMPT_AT, $lastAttemptAt);
    }

    /**
     * @return string
     */
    public function getQuoteUpdatedAt(): string
    {
        return (string) $this->getData(self::QUOTE_UPDATED_AT);
    }

    /**
     * @param string $quoteUpdatedAt
     * @return $this
     */
    public function setQuoteUpdatedAt(string $quoteUpdatedAt): self
    {
        return $this->setData(self::QUOTE_UPDATED_AT, $quoteUpdatedAt);
    }

    /**
     * @return string|null
     */
    public function getSentAt(): ?string
    {
        return $this->getData(self::SENT_AT);
    }

    /**
     * @param string|null $sentAt
     * @return $this
     */
    public function setSentAt(?string $sentAt): self
    {
        return $this->setData(self::SENT_AT, $sentAt);
    }

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string
    {
        return $this->getData(self::CREATED_AT);
    }

    /**
     * @return string|null
     */
    public function getUpdatedAt(): ?string
    {
        return $this->getData(self::UPDATED_AT);
    }
}
