<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Http;

use Centraliza\Connector\Messaging\Api\QuotaReservationInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Centraliza allows each API key 60 requests a minute, counted together for every route. The order and cart
 * messages use the same key, so the connection events reserve their request in the same table
 * (centraliza_rate_limit) with the lowest priority, "bulk": they stop for the minute when only the requests kept
 * for the messages are left (centraliza_connector/delivery/bulk_quota_floor).
 */
class SharedQuota
{
    public const ROUTE = 'admin/events';

    /**
     * A window lasts 60 s: waiting longer never frees a request sooner, and a held message waits in the retry
     * table instead of in a sleeping consumer.
     */
    private const MAX_WAIT_SECONDS = 60;

    /**
     * A 429 blocks the key for one window, as the messaging client does.
     */
    private const MAX_RETRY_AFTER_BLOCK_SECONDS = 60;

    /**
     * The quota is 60 per minute: a far higher header was never granted and would skip throttling.
     */
    private const MAX_TRUSTED_REMAINING = 1000;

    public function __construct(
        private readonly QuotaReservationInterface $quota,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Reserves one request, waiting once for the window to end when it is near.
     *
     * @return int 0 when reserved, otherwise how many seconds until a request may be free
     * @throws Throwable when the quota cannot be read; nothing may be sent unreserved
     */
    public function take(string $apiKey): int
    {
        $hash = $this->hash($apiKey);
        $wait = $this->tryReserve($hash);
        if ($wait > 0 && $wait <= self::MAX_WAIT_SECONDS) {
            $this->pause($wait);
            $wait = $this->tryReserve($hash);
        }

        return $wait;
    }

    /**
     * Keeps the shared count in step with what Centraliza answered. Never throws: the answer stands either way.
     *
     * 403 and 404 are left out on purpose: for this route they can mean the channel id is wrong, and recording
     * them would pause the order messages, which use the same route without a channel.
     */
    public function record(string $apiKey, ResponseInterface $response): void
    {
        $hash = $this->hash($apiKey);
        $status = $response->getStatusCode();
        try {
            if ($status === 429) {
                // A 429 carries no X-RateLimit-Remaining, yet it states that nothing is left.
                $retryAfter = $this->retryAfter($response->getHeaderLine('Retry-After'));
                $this->quota->block(
                    $hash,
                    min($retryAfter ?? self::MAX_RETRY_AFTER_BLOCK_SECONDS, self::MAX_RETRY_AFTER_BLOCK_SECONDS)
                );
                $this->quota->recordServerRemaining($hash, 0);

                return;
            }

            $remaining = $response->getHeaderLine('X-RateLimit-Remaining');
            if (ctype_digit($remaining)) {
                $this->quota->recordServerRemaining($hash, min((int)$remaining, self::MAX_TRUSTED_REMAINING));
            }

            if ($status === 401) {
                // A refused key pauses every route of the key for 15 minutes, messages included: they would be refused too.
                $this->quota->refuse($hash, self::ROUTE, 401);
            } elseif ($status >= 200 && $status < 300) {
                $this->quota->clearRefusal($hash, QuotaReservationInterface::PRIORITY_BULK, self::ROUTE);
            }
        } catch (Throwable $e) {
            $this->logger->warning(
                'Centraliza Connector could not record the answer in the shared quota.',
                ['exception_class' => $e::class]
            );
        }
    }

    /**
     * Sleeps in the consumer: the queue keeps the rest waiting meanwhile.
     */
    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * @return int 0 when reserved, otherwise seconds to wait (at least 1)
     */
    private function tryReserve(string $hash): int
    {
        if ($this->quota->reserve($hash, QuotaReservationInterface::PRIORITY_BULK, self::ROUTE)) {
            return 0;
        }

        return max(1, $this->quota->getWaitSeconds($hash, QuotaReservationInterface::PRIORITY_BULK, self::ROUTE));
    }

    /**
     * The same hash as the messaging config (Messaging\Model\Config::hashApiKey), so both share one row.
     */
    private function hash(string $apiKey): string
    {
        return hash('sha256', $apiKey);
    }

    private function retryAfter(string $value): ?int
    {
        $value = trim($value);
        if (ctype_digit($value)) {
            return (int)$value;
        }
        $date = $value === '' ? false : strtotime($value);

        return $date === false ? null : max(0, $date - time());
    }
}
