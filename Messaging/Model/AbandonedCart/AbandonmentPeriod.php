<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\AbandonedCart;

use Centraliza\Connector\Messaging\Api\AbandonedCartConfigInterface;
use Centraliza\Connector\Messaging\Model\ResourceModel\DatabaseClock;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class AbandonmentPeriod
{
    private const SECONDS_PER_MINUTE = 60;

    private const SECONDS_PER_DAY = 86400;

    /**
     * Magento's quote lifetime, in days: the sales_clean_quotes cron deletes quotes older than this.
     */
    private const PATH_QUOTE_LIFETIME_DAYS = 'checkout/cart/delete_quote_after';

    /**
     * Magento's own default for the quote lifetime, used when the stored value is unreadable.
     */
    private const DEFAULT_QUOTE_LIFETIME_DAYS = 30;

    /**
     * @param AbandonedCartConfigInterface $config
     * @param DatabaseClock $databaseClock
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly AbandonedCartConfigInterface $config,
        private readonly DatabaseClock $databaseClock,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Range a cart of the website must fall in, in UTC; null when none can.
     *
     * @param int $websiteId
     * @return array{from: string, to: string, activity_from: string}|null activity_from empty when unbounded
     */
    public function get(int $websiteId): ?array
    {
        $abandonMinutes = $this->config->getAbandonMinutes($websiteId);
        $sendLimitMinutes = $this->config->getSendLimitMinutes($websiteId);

        // From the send limit on, no cart is both abandoned and still sendable. The configuration page
        // refuses such a value; direct SQL, a restored dump or a hand-edited config file do not.
        if (!empty($sendLimitMinutes) && $abandonMinutes >= $sendLimitMinutes) {
            $this->logger->warning(
                'Centraliza abandoned cart selection skipped: abandonment time not shorter than the send limit',
                [
                    'website_id' => $websiteId,
                    'abandon_minutes' => $abandonMinutes,
                    'send_limit_minutes' => $sendLimitMinutes,
                ]
            );

            return null;
        }

        // Counted from now, never from the last run, so a cron that stopped still sees every cart.
        $now = $this->databaseClock->getUtcTimestamp();
        // Always the quote lifetime, whatever the send limit: this bound exists for the store/updated_at index,
        // not for the rule. The rule is the activity bound below, which no index covers.
        $from = $this->dateTime->gmtDate(null, $this->getOldestQuoteTime($now, $websiteId));

        // Empty when there is no send limit: without one the bound would be the quote lifetime, which the window
        // above already enforces, and the queries would pay for a condition that can never exclude a cart.
        $activityFrom = '';
        if (!empty($sendLimitMinutes)) {
            // "Idle for longer than this" is counted from the last activity, not from the last change: a cart the
            // customer opened an hour ago is not idle, however old its last change is. Counting it from the change
            // would drop exactly the carts this feature exists for, the ones a customer comes back to look at.
            $activityFrom = $this->dateTime->gmtDate(null, $this->getOldestSendableTime($now, $sendLimitMinutes));
        }

        return [
            'from' => $from,
            'to' => $this->dateTime->gmtDate(null, $now - $abandonMinutes * self::SECONDS_PER_MINUTE),
            'activity_from' => $activityFrom,
        ];
    }

    /**
     * When a cart touched again counts as abandoned, in UTC.
     *
     * @param string $lastActivityAt the customer's last activity on the cart, in UTC
     * @param int $minutes minutes without activity after which the cart counts as abandoned
     * @return string|null null when it already counts as abandoned or the date is unreadable
     */
    public function getAbandonedAt(string $lastActivityAt, int $minutes): ?string
    {
        $activityAt = $this->toTimestamp($lastActivityAt);
        if (empty($activityAt)) {
            return null;
        }

        $abandonedAt = $activityAt + $minutes * self::SECONDS_PER_MINUTE;
        if ($abandonedAt <= $this->databaseClock->getUtcTimestamp()) {
            return null;
        }

        return $this->dateTime->gmtDate(null, $abandonedAt);
    }

    /**
     * Whether the date lies further back than the website's send limit; never when the website has none.
     *
     * @param string $since a date in UTC
     * @param int $websiteId
     * @return bool
     */
    public function isPastSendLimit(string $since, int $websiteId): bool
    {
        $sendLimitMinutes = $this->config->getSendLimitMinutes($websiteId);
        if (empty($sendLimitMinutes)) {
            return false;
        }

        // An unreadable date cannot show the cart is recent enough, so it is never sent.
        $sinceTimestamp = $this->toTimestamp($since);
        if (empty($sinceTimestamp)) {
            return true;
        }

        return $sinceTimestamp
            < $this->getOldestSendableTime($this->databaseClock->getUtcTimestamp(), $sendLimitMinutes);
    }

    /**
     * With no send limit, the range still starts at Magento's quote lifetime.
     *
     * @param int $now
     * @param int $websiteId
     * @return int the oldest time, as a timestamp, a quote still exists from
     */
    private function getOldestQuoteTime(int $now, int $websiteId): int
    {
        // Magento deletes quotes older than its quote lifetime anyway, so this reaches no cart a send limit of 0
        // would have reached; an explicit bound keeps the selection on the store/updated_at index instead of
        // reading every quote of the store view on each run.
        $days = (int) $this->scopeConfig->getValue(
            self::PATH_QUOTE_LIFETIME_DAYS,
            ScopeInterface::SCOPE_WEBSITE,
            $websiteId
        );
        if ($days <= 0) {
            $days = self::DEFAULT_QUOTE_LIFETIME_DAYS;
        }

        return $now - $days * self::SECONDS_PER_DAY;
    }

    /**
     * @param int $now
     * @param int $sendLimitMinutes
     * @return int the oldest time, as a timestamp, a send may still be counted from
     */
    private function getOldestSendableTime(int $now, int $sendLimitMinutes): int
    {
        return $now - $sendLimitMinutes * self::SECONDS_PER_MINUTE;
    }

    /**
     * @param string $quoteUpdatedAt
     * @return int 0 when the date is empty or unreadable
     */
    private function toTimestamp(string $quoteUpdatedAt): int
    {
        // Checked first: strtotime() reads an empty string as "now".
        if (empty($quoteUpdatedAt)) {
            return 0;
        }

        return (int) strtotime($quoteUpdatedAt . ' UTC');
    }
}
