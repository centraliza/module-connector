<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Api;

use Centraliza\Connector\Messaging\Api\QuotaReservationInterface;
use Centraliza\Connector\Messaging\Exception\CentralizaAuthException;
use Centraliza\Connector\Messaging\Model\LegacyModules;
use Centraliza\Connector\Messaging\Model\ResourceModel\RateLimit;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class QuotaReservation implements QuotaReservationInterface
{
    public const PATH_BULK_QUOTA_FLOOR = 'centraliza_connector/delivery/bulk_quota_floor';

    /**
     * Requests per minute per key; 0 turns the local limit off.
     */
    public const PATH_REQUEST_LIMIT = 'centraliza_connector/delivery/request_limit';

    /**
     * Measured by the legacy modules, which keep it fixed in their own limiter.
     */
    public const DEFAULT_LIMIT = 60;

    /**
     * The bulk floor must stay under the limit and over the marketing floor (20), so 21 is the lowest bulk floor
     * and 22 the lowest limit that still leaves the connection events one request.
     */
    public const MIN_LIMIT = 22;

    /**
     * Shown as what is left while the limit is off.
     */
    private const UNLIMITED_REMAINING = 1000;

    /**
     * A person is waiting on the screen: may spend the last request.
     */
    private const INTERACTIVE_FLOOR = 0;

    /**
     * Keeps 10 requests for the admin screen while a cron sends.
     */
    private const TRANSACTIONAL_FLOOR = 10;

    /**
     * A reminder can wait a minute, an order notification should not: 10 more kept for them (chosen value).
     */
    private const MARKETING_FLOOR = 20;

    /**
     * An estimate, hence configurable; from 21 to one under the limit keeps connection events stopping before
     * marketing and still sending.
     */
    private const DEFAULT_BULK_FLOOR = 30;
    public const MIN_BULK_FLOOR = 21;

    /**
     * Each check spends the key's shared quota: repeated checks could starve customer messages (user decision).
     */
    private const CHECK_INTERVAL_SECONDS = 60;

    /**
     * Length of centraliza_rate_limit_refusal.route.
     */
    private const MAX_ROUTE_LENGTH = 255;

    private const UNKNOWN_PRIORITY = 'Unknown Centraliza request priority "%s".';
    private const INVALID_ROUTE = 'The Centraliza route must have 1 to ' . self::MAX_ROUTE_LENGTH
        . ' characters once normalised.';

    /**
     * @param RateLimit $rateLimitResource
     * @param ScopeConfigInterface $scopeConfig
     * @param LoggerInterface $logger
     * @param LegacyModules $legacyModules
     */
    public function __construct(
        private readonly RateLimit $rateLimitResource,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger,
        private readonly LegacyModules $legacyModules
    ) {
    }

    /**
     * @param string $apiKeyHash
     * @param string $priority
     * @param string $route
     * @return bool
     */
    public function reserve(string $apiKeyHash, string $priority, string $route): bool
    {
        $route = $this->normaliseRoute($route);
        $limit = $this->getLimit();
        if ($limit === null) {
            // An unknown priority still fails, as it does with a limit.
            $this->getFloor($priority, self::DEFAULT_LIMIT);

            return !$this->rateLimitResource->isPaused($apiKeyHash, $route, $priority !== self::PRIORITY_INTERACTIVE);
        }

        return $this->rateLimitResource->reserve(
            $apiKeyHash,
            $this->getFloor($priority, $limit),
            $route,
            $priority !== self::PRIORITY_INTERACTIVE,
            $limit
        );
    }

    /**
     * @param string $apiKeyHash
     * @param int $requests
     * @return void
     */
    public function consume(string $apiKeyHash, int $requests): void
    {
        if ($this->getLimit() === null) {
            return;
        }

        $this->rateLimitResource->consume($apiKeyHash, $requests);
    }

    /**
     * @param string $apiKeyHash
     * @param int $remaining
     * @return void
     */
    public function recordServerRemaining(string $apiKeyHash, int $remaining): void
    {
        $limit = $this->getLimit();
        if ($limit === null) {
            return;
        }

        $this->rateLimitResource->recordServerRemaining($apiKeyHash, $remaining, $limit);
    }

    /**
     * @param string $apiKeyHash
     * @param int $seconds
     * @return void
     */
    public function block(string $apiKeyHash, int $seconds): void
    {
        $this->rateLimitResource->block($apiKeyHash, $seconds);
    }

    /**
     * @param string $apiKeyHash
     * @param string $route
     * @param int $httpStatus
     * @return void
     */
    public function refuse(string $apiKeyHash, string $route, int $httpStatus): void
    {
        $route = $this->normaliseRoute($route);

        // User decision: a 401 is an invalid key and pauses the whole key; a 403 lacks one route's permission.
        if ($httpStatus === CentralizaAuthException::HTTP_UNAUTHORIZED) {
            $this->rateLimitResource->refuseKey($apiKeyHash, RateLimit::REFUSAL_SECONDS);
        } else {
            $this->rateLimitResource->refuseRoute($apiKeyHash, $route, RateLimit::REFUSAL_SECONDS);
        }

        // A refusal is rare, so it is a cheap moment to purge stale rows.
        $this->deleteStaleRowsSafely();
    }

    /**
     * @param string $apiKeyHash
     * @param string $priority
     * @param string $route
     * @return void
     */
    public function clearRefusal(string $apiKeyHash, string $priority, string $route): void
    {
        $route = $this->normaliseRoute($route);
        // User decision: a person confirming the key ends the key-wide pause, never a route's 403 pause.
        if ($priority === self::PRIORITY_INTERACTIVE) {
            $this->rateLimitResource->clearKeyRefusal($apiKeyHash);

            return;
        }

        $this->rateLimitResource->clearRouteRefusal($apiKeyHash, $route);
    }

    /**
     * @param string $apiKeyHash
     * @param string $priority
     * @return int
     */
    public function getRemaining(string $apiKeyHash, string $priority): int
    {
        $limit = $this->getLimit();
        if ($limit === null) {
            return $this->rateLimitResource->getRemaining(
                $apiKeyHash,
                $priority !== self::PRIORITY_INTERACTIVE,
                self::UNLIMITED_REMAINING
            ) === 0 ? 0 : self::UNLIMITED_REMAINING;
        }

        return $this->rateLimitResource->getRemaining($apiKeyHash, $priority !== self::PRIORITY_INTERACTIVE, $limit);
    }

    /**
     * @param string $apiKeyHash
     * @param string $priority
     * @param string $route
     * @return int
     */
    public function getWaitSeconds(string $apiKeyHash, string $priority, string $route): int
    {
        return $this->rateLimitResource->getWaitSeconds(
            $apiKeyHash,
            $this->normaliseRoute($route),
            $priority !== self::PRIORITY_INTERACTIVE,
            $this->getLimit() !== null
        );
    }

    /**
     * @param string $checkHash
     * @return bool
     */
    public function claimCheck(string $checkHash): bool
    {
        $claimed = $this->rateLimitResource->claimCheck($checkHash, self::CHECK_INTERVAL_SECONDS);

        // A check is rare (one per minute at most), so it is a cheap moment to purge stale rows.
        $this->deleteStaleRowsSafely();

        return $claimed;
    }

    /**
     * @param string $checkHash
     * @return int
     */
    public function getCheckWaitSeconds(string $checkHash): int
    {
        return $this->rateLimitResource->getCheckWaitSeconds($checkHash, self::CHECK_INTERVAL_SECONDS);
    }

    /**
     * Purge stale rows; a failure is only logged, the write before it already happened.
     *
     * @return void
     */
    private function deleteStaleRowsSafely(): void
    {
        try {
            $this->rateLimitResource->deleteStaleRows();
        } catch (Throwable $throwable) {
            $this->logger->warning(
                'Centraliza stale quota rows could not be purged',
                ['exception_class' => $throwable::class]
            );
        }
    }

    /**
     * @param string $route
     * @return string
     * @throws InvalidArgumentException
     */
    private function normaliseRoute(string $route): string
    {
        // Every spelling of one path (query, fragment, dot segments, extra slashes) must hit the same 403 pause.
        $path = preg_split('/[?#]/', $route, 2)[0];
        $path = (string) preg_replace('#/{2,}#', '/', $path);
        $path = trim(UriResolver::removeDotSegments($path), '/');

        if (empty($path) || mb_strlen($path) > self::MAX_ROUTE_LENGTH) {
            throw new InvalidArgumentException(self::INVALID_ROUTE);
        }

        return $path;
    }

    /**
     * Requests per minute per key, or null when the local limit is off.
     *
     * While the legacy Centraliza_Base is enabled it counts in the same table against its fixed 60: a higher
     * limit here would let the connection events spend what its messages wait for, so 60 holds until then.
     *
     * @return int|null
     */
    public function getLimit(): ?int
    {
        $configured = $this->scopeConfig->getValue(self::PATH_REQUEST_LIMIT);
        if ($configured === null || trim((string) $configured) === '') {
            $limit = self::DEFAULT_LIMIT;
        } else {
            $limit = (int) $configured;
        }
        if ($limit <= 0) {
            return null;
        }

        // CLI or SQL writes skip the admin validation.
        if ($limit < self::MIN_LIMIT) {
            $limit = self::DEFAULT_LIMIT;
        }
        if ($this->legacyModules->limiterActive()) {
            $limit = min($limit, self::DEFAULT_LIMIT);
        }

        return $limit;
    }

    /**
     * @param string $priority
     * @param int $limit
     * @return int
     */
    private function getFloor(string $priority, int $limit): int
    {
        return match ($priority) {
            self::PRIORITY_INTERACTIVE => self::INTERACTIVE_FLOOR,
            self::PRIORITY_TRANSACTIONAL => self::TRANSACTIONAL_FLOOR,
            self::PRIORITY_MARKETING => self::MARKETING_FLOOR,
            self::PRIORITY_BULK => $this->getBulkFloor($limit),
            default => throw new InvalidArgumentException(sprintf(self::UNKNOWN_PRIORITY, $priority)),
        };
    }

    /**
     * @param int $limit
     * @return int
     */
    private function getBulkFloor(int $limit): int
    {
        $floor = (int) $this->scopeConfig->getValue(self::PATH_BULK_QUOTA_FLOOR);

        // CLI or SQL writes skip the admin validation, and the limit can be lowered after the floor was saved.
        if ($floor < self::MIN_BULK_FLOOR || $floor >= $limit) {
            return max(self::MIN_BULK_FLOOR, min(self::DEFAULT_BULK_FLOOR, $limit - 1));
        }

        return $floor;
    }
}
