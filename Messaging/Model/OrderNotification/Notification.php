<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\OrderNotification;

use Centraliza\Connector\Messaging\Exception\CentralizaApiException;
use Centraliza\Connector\Messaging\Exception\CentralizaRateLimitException;
use Centraliza\Connector\Messaging\Model\ResourceModel\OrderNotification\Notification as NotificationResource;
use Magento\Framework\Model\AbstractModel;

class Notification extends AbstractModel
{
    /**
     * Centraliza event contract: the order event types this queue sends on the events route.
     */
    public const EVENT_ORDER_STATUS_UPDATED = 'order.status_updated';
    public const EVENT_ORDER_CREATED = 'order.created';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENDING = 'sending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_SENT = 'sent';
    public const STATUS_NOT_SENT = 'not_sent';
    public const STATUS_RETRY = 'retry';
    public const STATUS_FAILED = 'failed';

    /**
     * The request may have reached Centraliza without an answer: never resent, since deduplication on the
     * event's idempotency_key is not confirmed by Centraliza.
     */
    public const STATUS_UNKNOWN = 'unknown';

    /**
     * No further send is made from these states, except an administrator's resend (RESENDABLE_STATUSES).
     */
    public const FINAL_STATUSES = [
        self::STATUS_SENT,
        self::STATUS_NOT_SENT,
        self::STATUS_FAILED,
        self::STATUS_UNKNOWN,
    ];

    /**
     * Centraliza may repeat a callback: only the first one about a dispatch changes the row.
     */
    public const CALLBACK_SOURCE_STATUSES = [self::STATUS_DELIVERED];

    /**
     * Transient failures only: a 404 or 422 is a contract mismatch, and repeating it spends the key's shared quota.
     */
    public const RETRYABLE_CODES = [
        CentralizaRateLimitException::HTTP_STATUS,
        500,
        502,
        503,
        504,
        CentralizaApiException::CONNECTION_ERROR_CODE,
    ];

    /**
     * Neither reached the customer; unknown is left out, since its message may already have.
     */
    public const RESENDABLE_STATUSES = [self::STATUS_FAILED, self::STATUS_NOT_SENT];

    /**
     * Wait before a retried or postponed row is sent again: five sends span about an hour, and a row
     * put back is not reserved again in the same round.
     */
    public const RETRY_DELAY_MINUTES = 15;

    /**
     * Bounded like the external_id column, so a malformed id is never stored, queried or logged.
     */
    public const EXTERNAL_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/D';

    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(NotificationResource::class);
    }
}
