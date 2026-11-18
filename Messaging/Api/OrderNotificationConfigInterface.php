<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api;

interface OrderNotificationConfigInterface
{
    /**
     * Five sends fifteen minutes apart span about an hour, enough for a common outage.
     */
    public const DEFAULT_MAX_ATTEMPTS = 5;

    /**
     * @param int|null $websiteId
     * @return bool
     */
    public function isEnabled(?int $websiteId = null): bool;

    /**
     * Whether the website sends the order created event and the origin cart of orders from a Centraliza link.
     *
     * @param int|null $websiteId
     * @return bool
     */
    public function isOrderCreatedEnabled(?int $websiteId = null): bool;

    /**
     * Whether a change to this order status notifies the customer.
     *
     * @param string $status
     * @param int|null $websiteId
     * @return bool
     */
    public function isStatusNotified(string $status, ?int $websiteId = null): bool;

    /**
     * @param int|null $websiteId
     * @return bool
     */
    public function isRetryEnabled(?int $websiteId = null): bool;

    /**
     * @param int|null $websiteId
     * @return int
     */
    public function getGiveUpHours(?int $websiteId = null): int;

    /**
     * The configured attempt limit, the first send included; the send path reads getMaxSends().
     *
     * @param int|null $websiteId
     * @return int
     */
    public function getMaxAttempts(?int $websiteId = null): int;

    /**
     * Sends a notification may use on the website: one with automatic resend off, else the attempt limit.
     *
     * @param int|null $websiteId
     * @return int
     */
    public function getMaxSends(?int $websiteId = null): int;
}
