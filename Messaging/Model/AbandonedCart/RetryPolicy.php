<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\AbandonedCart;

use Centraliza\Connector\Messaging\Api\AbandonedCartConfigInterface;
use Centraliza\Connector\Messaging\Model\ResourceModel\DatabaseClock;
use Magento\Framework\Stdlib\DateTime\DateTime;

class RetryPolicy
{
    private const SECONDS_PER_MINUTE = 60;

    /**
     * Fixed, no longer a store setting: a temporary Centraliza failure is resent up to five times,
     * waiting 5, 10, 20, 40 and 80 minutes, so a short outage does not lose the message.
     */
    private const MAX_RETRIES = 5;
    private const RETRY_BASE_MINUTES = 5;

    /**
     * @param AbandonedCartConfigInterface $config
     * @param DatabaseClock $databaseClock
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly AbandonedCartConfigInterface $config,
        private readonly DatabaseClock $databaseClock,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * When to resend after a temporary failure, or null when the failure is final.
     *
     * @param int $attemptsBefore attempts the row held before this one
     * @param int $websiteId
     * @param bool $requestPossiblySent whether Centraliza may have received it without answering
     * @return string|null
     */
    public function getRetryAt(int $attemptsBefore, int $websiteId, bool $requestPossiblySent): ?string
    {
        // A send that may have reached Centraliza (timeout, 502, 504) is resent once at most:
        // each resend may be another WhatsApp to the customer.
        if ($requestPossiblySent && $attemptsBefore >= 1) {
            return null;
        }

        // The limit counts resends, not the first send: a cart gets at most 1 + MAX_RETRIES attempts.
        if ($attemptsBefore >= self::MAX_RETRIES) {
            return null;
        }

        return $this->getWaitEnd($attemptsBefore + 1, $websiteId, 0);
    }

    /**
     * When to resend after a clean 429; a rate limit is never final.
     *
     * @param int $attemptsBefore attempts the row held before this one
     * @param int $websiteId
     * @param int $retryAfterSeconds what Centraliza's Retry-After asked for, 0 when absent
     * @return string
     */
    public function getRateLimitRetryAt(int $attemptsBefore, int $websiteId, int $retryAfterSeconds): string
    {
        return $this->getWaitEnd($attemptsBefore + 1, $websiteId, $retryAfterSeconds);
    }

    /**
     * @param int $retryNumber
     * @param int $websiteId
     * @param int $retryAfterSeconds
     * @return string
     */
    private function getWaitEnd(int $retryNumber, int $websiteId, int $retryAfterSeconds): string
    {
        // Exponential backoff: base, 2 x base, 4 x base... A 429 keeps counting attempts past
        // MAX_RETRIES, so the exponent is capped to keep the wait finite when there is no send limit.
        $backoffSeconds = self::RETRY_BASE_MINUTES
            * (2 ** (min($retryNumber, self::MAX_RETRIES) - 1))
            * self::SECONDS_PER_MINUTE;

        // Waiting less than Retry-After only earns another 429.
        $waitSeconds = max($backoffSeconds, $retryAfterSeconds);

        // A wait past the website's send limit could only end failed at the next reservation, so no
        // wait is longer. Retry-After comes from Centraliza and has no bound of its own: even with no
        // send limit, the wait stops at the longest limit the store can set, since a longer one parks
        // the row past the quote lifetime or overflows the TIMESTAMP column (2038).
        $maxWaitMinutes = Config::MAX_SEND_LIMIT_MINUTES;
        $sendLimitMinutes = $this->config->getSendLimitMinutes($websiteId);
        if ($sendLimitMinutes !== Config::NO_SEND_LIMIT) {
            $maxWaitMinutes = min($maxWaitMinutes, $sendLimitMinutes);
        }
        $waitSeconds = min($waitSeconds, $maxWaitMinutes * self::SECONDS_PER_MINUTE);

        return $this->dateTime->gmtDate(null, $this->databaseClock->getUtcTimestamp() + $waitSeconds);
    }
}
