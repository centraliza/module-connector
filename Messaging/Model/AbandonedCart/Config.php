<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\AbandonedCart;

use Centraliza\Connector\Messaging\Api\ConfigInterface as BaseConfigInterface;
use Centraliza\Connector\Messaging\Api\ScopeResolverInterface;
use Centraliza\Connector\Messaging\Api\AbandonedCartConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Config implements AbandonedCartConfigInterface
{
    private const PATH_ENABLED = 'centraliza_connector/abandoned_cart/enabled';
    private const PATH_ABANDON_MINUTES = 'centraliza_connector/abandoned_cart/abandon_minutes';
    public const PATH_SEND_LIMIT_MINUTES = 'centraliza_connector/abandoned_cart/send_limit_minutes';
    public const PATH_MAX_NOTIFICATIONS = 'centraliza_connector/abandoned_cart/max_notifications';

    /**
     * Zero is valid on purpose: the cart is sent on the next run, so a store can test the flow quickly.
     */
    public const MIN_ABANDON_MINUTES = 0;

    /**
     * Never longer than the longest send limit: with no limit, a larger value would overflow the selection
     * window and silently stop every send of the website.
     */
    public const MAX_ABANDON_MINUTES = self::MAX_SEND_LIMIT_MINUTES;

    /**
     * Zero means no limit. Up to a week otherwise: a message about a cart left longer ago reads as spam.
     */
    public const NO_SEND_LIMIT = 0;
    public const MAX_SEND_LIMIT_MINUTES = 10080;

    /**
     * Same as etc/config.xml, for a stored empty or non-numeric value that would otherwise read as zero.
     */
    private const DEFAULT_ABANDON_MINUTES = 60;

    /**
     * Business rule kept as the default (24 hours): a message about a cart left more than a day ago reads
     * as spam and no longer matches the purchase.
     */
    public const DEFAULT_SEND_LIMIT_MINUTES = 1440;

    /**
     * Zero means no limit on purpose: the store owner may resend on every change of the cart.
     */
    public const UNLIMITED_NOTIFICATIONS = 0;

    /**
     * One message per cart by default, the behavior before resends after a change existed.
     */
    public const DEFAULT_MAX_NOTIFICATIONS = 1;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param ScopeResolverInterface $scopeResolver
     * @param BaseConfigInterface $baseConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ScopeResolverInterface $scopeResolver,
        private readonly BaseConfigInterface $baseConfig
    ) {
    }

    /**
     * @param int|null $websiteId
     * @return bool
     */
    public function isEnabled(?int $websiteId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH_ENABLED, ...$this->scopeResolver->forRead($websiteId));
    }

    /**
     * @param int $websiteId
     * @return bool
     */
    public function isActive(int $websiteId): bool
    {
        return $this->isEnabled($websiteId) && $this->baseConfig->isReady($websiteId);
    }

    /**
     * @param int|null $websiteId
     * @return int
     */
    public function getAbandonMinutes(?int $websiteId = null): int
    {
        $minutes = $this->getIntValue(self::PATH_ABANDON_MINUTES, $websiteId, self::DEFAULT_ABANDON_MINUTES);

        // A direct SQL write, a restored dump or a hand-edited env.php/config.php skips the backend model:
        // a negative value falls back to the default instead of sending at once.
        if ($minutes < self::MIN_ABANDON_MINUTES) {
            return self::DEFAULT_ABANDON_MINUTES;
        }

        return min(self::MAX_ABANDON_MINUTES, $minutes);
    }

    /**
     * @param int|null $websiteId
     * @return int
     */
    public function getSendLimitMinutes(?int $websiteId = null): int
    {
        $minutes = $this->getIntValue(self::PATH_SEND_LIMIT_MINUTES, $websiteId, self::DEFAULT_SEND_LIMIT_MINUTES);

        // A direct SQL write, a restored dump or a hand-edited env.php/config.php skips the backend model:
        // a negative value falls back to the default, never to 0, which would mean no limit.
        if ($minutes < self::NO_SEND_LIMIT) {
            return self::DEFAULT_SEND_LIMIT_MINUTES;
        }

        return min(self::MAX_SEND_LIMIT_MINUTES, $minutes);
    }

    /**
     * @param int|null $websiteId
     * @return int
     */
    public function getMaxNotifications(?int $websiteId = null): int
    {
        $total = $this->getIntValue(self::PATH_MAX_NOTIFICATIONS, $websiteId, self::DEFAULT_MAX_NOTIFICATIONS);

        // A direct SQL write, a restored dump or a hand-edited env.php/config.php skips the backend model:
        // a negative value falls back to the default (one message), never to 0, which means unlimited.
        if ($total < self::UNLIMITED_NOTIFICATIONS) {
            return self::DEFAULT_MAX_NOTIFICATIONS;
        }

        return $total;
    }

    /**
     * @param string $path
     * @param int|null $websiteId
     * @param int $default
     * @return int
     */
    private function getIntValue(string $path, ?int $websiteId, int $default): int
    {
        $value = $this->scopeConfig->getValue($path, ...$this->scopeResolver->forRead($websiteId));
        if (!is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }
}
