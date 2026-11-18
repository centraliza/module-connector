<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api;

interface AbandonedCartConfigInterface
{
    /**
     * @param int|null $websiteId
     * @return bool
     */
    public function isEnabled(?int $websiteId = null): bool;

    /**
     * Whether the abandoned cart flow may run for the website: enabled here and the Base ready.
     *
     * @param int $websiteId
     * @return bool
     */
    public function isActive(int $websiteId): bool;

    /**
     * @param int|null $websiteId
     * @return int
     */
    public function getAbandonMinutes(?int $websiteId = null): int;

    /**
     * Minutes after the cart's last change past which the cart is no longer sent; 0 means no limit.
     *
     * @param int|null $websiteId
     * @return int
     */
    public function getSendLimitMinutes(?int $websiteId = null): int;

    /**
     * Messages a cart can receive in total, the first included; 0 means no limit.
     *
     * @param int|null $websiteId
     * @return int
     */
    public function getMaxNotifications(?int $websiteId = null): int;
}
