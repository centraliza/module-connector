<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api;

/**
 * Records an order status change as a notification waiting for the send cron.
 */
interface QueueOrderNotificationServiceInterface
{
    /**
     * Record a status change as a pending notification for the send cron.
     *
     * @param int $orderId
     * @param string $incrementId
     * @param string|null $fromStatus
     * @param string $toStatus
     * @param int $websiteId
     * @return bool false when the notification could not be recorded
     */
    public function queue(
        int $orderId,
        string $incrementId,
        ?string $fromStatus,
        string $toStatus,
        int $websiteId
    ): bool;

    /**
     * Record the creation of an order that came from a Centraliza link, once per order.
     *
     * @param int $orderId
     * @param string $incrementId
     * @param string $status the order status when it was created
     * @param int $websiteId
     * @return void
     */
    public function queueOrderCreated(int $orderId, string $incrementId, string $status, int $websiteId): void;
}
