<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api\Data;

interface AbandonedCartInterface
{
    public const ENTITY_ID = 'entity_id';
    public const QUOTE_ID = 'quote_id';
    public const STORE_ID = 'store_id';
    public const WEBSITE_ID = 'website_id';
    public const REMINDER_NUMBER = 'reminder_number';
    public const STATUS = 'status';
    public const ATTEMPTS = 'attempts';
    public const NEXT_ATTEMPT_AT = 'next_attempt_at';
    public const LAST_HTTP_STATUS = 'last_http_status';
    public const LAST_ERROR = 'last_error';
    public const LAST_ATTEMPT_AT = 'last_attempt_at';
    public const QUOTE_UPDATED_AT = 'quote_updated_at';

    /**
     * The customer's last activity as it stood when the row was selected: the wait that was applied.
     */
    public const LAST_ACTIVITY_AT = 'last_activity_at';

    public const SENT_AT = 'sent_at';
    public const CART_SIGNATURE = 'cart_signature';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    /**
     * Selected and waiting to be sent: the cart's first message or a resend after a change.
     */
    public const STATUS_PENDING = 'pending';

    /**
     * Reserved by a send round; no other round may send it while it holds this state.
     */
    public const STATUS_PROCESSING = 'processing';

    /**
     * Accepted by Centraliza. Final for this row: a later change by the customer may add a new row.
     */
    public const STATUS_SUCCESS = 'success';

    /**
     * A temporary failure; sent again once next_attempt_at is reached.
     */
    public const STATUS_RETRY = 'retry';

    /**
     * A permanent failure or the retry limit reached. Final for this row: never retried.
     */
    public const STATUS_FAILED = 'failed';

    /**
     * @return int|null
     */
    public function getEntityId();

    /**
     * @param int $entityId
     * @return $this
     */
    public function setEntityId($entityId);

    /**
     * @return int
     */
    public function getQuoteId(): int;

    /**
     * @param int $quoteId
     * @return $this
     */
    public function setQuoteId(int $quoteId): self;

    /**
     * @return int
     */
    public function getStoreId(): int;

    /**
     * @param int $storeId
     * @return $this
     */
    public function setStoreId(int $storeId): self;

    /**
     * @return int
     */
    public function getWebsiteId(): int;

    /**
     * @param int $websiteId
     * @return $this
     */
    public function setWebsiteId(int $websiteId): self;

    /**
     * @return string
     */
    public function getStatus(): string;

    /**
     * @param string $status
     * @return $this
     */
    public function setStatus(string $status): self;

    /**
     * @return int
     */
    public function getAttempts(): int;

    /**
     * @param int $attempts
     * @return $this
     */
    public function setAttempts(int $attempts): self;

    /**
     * @return string|null
     */
    public function getNextAttemptAt(): ?string;

    /**
     * @param string|null $nextAttemptAt
     * @return $this
     */
    public function setNextAttemptAt(?string $nextAttemptAt): self;

    /**
     * @return int|null
     */
    public function getLastHttpStatus(): ?int;

    /**
     * @param int|null $lastHttpStatus
     * @return $this
     */
    public function setLastHttpStatus(?int $lastHttpStatus): self;

    /**
     * @return string|null
     */
    public function getLastError(): ?string;

    /**
     * @param string|null $lastError
     * @return $this
     */
    public function setLastError(?string $lastError): self;

    /**
     * @return string|null
     */
    public function getLastAttemptAt(): ?string;

    /**
     * @param string|null $lastAttemptAt
     * @return $this
     */
    public function setLastAttemptAt(?string $lastAttemptAt): self;

    /**
     * @return string
     */
    public function getQuoteUpdatedAt(): string;

    /**
     * @param string $quoteUpdatedAt
     * @return $this
     */
    public function setQuoteUpdatedAt(string $quoteUpdatedAt): self;

    /**
     * @return string|null
     */
    public function getSentAt(): ?string;

    /**
     * @param string|null $sentAt
     * @return $this
     */
    public function setSentAt(?string $sentAt): self;

    /**
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * @return string|null
     */
    public function getUpdatedAt(): ?string;
}
