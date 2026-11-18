<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\OrderNotification;

use Centraliza\Connector\Messaging\Api\ScopeResolverInterface;
use Centraliza\Connector\Messaging\Api\OrderNotificationConfigInterface;
use InvalidArgumentException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

class Config implements OrderNotificationConfigInterface
{
    public const PATH_ENABLED = 'centraliza_connector/order_notification/enabled';
    public const PATH_ORDER_CREATED_ENABLED = 'centraliza_connector/order_notification/order_created_enabled';
    public const PATH_NOTIFIED_STATUSES = 'centraliza_connector/order_notification/notified_statuses';
    public const PATH_RETRY_ENABLED = 'centraliza_connector/order_notification/retry_enabled';
    public const PATH_GIVE_UP_HOURS = 'centraliza_connector/order_notification/give_up_hours';
    public const PATH_MAX_ATTEMPTS = 'centraliza_connector/order_notification/max_attempts';

    /**
     * At most ten: each resend spends the key's shared quota, and ten sends already span about 2h15.
     */
    public const ATTEMPT_LIMIT_MIN = 1;
    public const ATTEMPT_LIMIT_MAX = 10;

    private const DEFAULT_GIVE_UP_HOURS = 24;

    /**
     * @var array<string, array>
     */
    private array $notifiedStatusesCache = [];

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Json $serializer
     * @param ScopeResolverInterface $scopeResolver
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Json $serializer,
        private readonly ScopeResolverInterface $scopeResolver,
        private readonly LoggerInterface $logger
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
     * @param int|null $websiteId
     * @return bool
     */
    public function isOrderCreatedEnabled(?int $websiteId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::PATH_ORDER_CREATED_ENABLED,
            ...$this->scopeResolver->forRead($websiteId)
        );
    }

    /**
     * @param string $status
     * @param int|null $websiteId
     * @return bool
     */
    public function isStatusNotified(string $status, ?int $websiteId = null): bool
    {
        // Rows saved before the template id was dropped still carry it; only the status is read.
        foreach ($this->getNotifiedStatusRows($websiteId) as $row) {
            if (($row['status'] ?? null) === $status) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param int|null $websiteId
     * @return bool
     */
    public function isRetryEnabled(?int $websiteId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH_RETRY_ENABLED, ...$this->scopeResolver->forRead($websiteId));
    }

    /**
     * @param int|null $websiteId
     * @return int
     */
    public function getGiveUpHours(?int $websiteId = null): int
    {
        $hours = (int) $this->scopeConfig->getValue(
            self::PATH_GIVE_UP_HOURS,
            ...$this->scopeResolver->forRead($websiteId)
        );

        if ($hours <= 0) {
            return self::DEFAULT_GIVE_UP_HOURS;
        }

        return $hours;
    }

    /**
     * @param int|null $websiteId
     * @return int
     */
    public function getMaxAttempts(?int $websiteId = null): int
    {
        $value = $this->scopeConfig->getValue(
            self::PATH_MAX_ATTEMPTS,
            ...$this->scopeResolver->forRead($websiteId)
        );

        if (!is_numeric($value)) {
            return self::DEFAULT_MAX_ATTEMPTS;
        }

        // A restored dump skips the backend model's range check.
        return max(self::ATTEMPT_LIMIT_MIN, min(self::ATTEMPT_LIMIT_MAX, (int) $value));
    }

    /**
     * @param int|null $websiteId
     * @return int
     */
    public function getMaxSends(?int $websiteId = null): int
    {
        if (!$this->isRetryEnabled($websiteId)) {
            return 1;
        }

        return $this->getMaxAttempts($websiteId);
    }

    /**
     * @param int|null $websiteId
     * @return array
     */
    private function getNotifiedStatusRows(?int $websiteId): array
    {
        $cacheKey = (string) ($websiteId ?? 'default');
        if (isset($this->notifiedStatusesCache[$cacheKey])) {
            return $this->notifiedStatusesCache[$cacheKey];
        }

        $raw = $this->scopeConfig->getValue(
            self::PATH_NOTIFIED_STATUSES,
            ...$this->scopeResolver->forRead($websiteId)
        );

        // A dynamic-rows value never saved comes back as an empty string, not an array.
        if (!is_string($raw) || empty($raw)) {
            return $this->notifiedStatusesCache[$cacheKey] = [];
        }

        try {
            $decoded = $this->serializer->unserialize($raw);
        } catch (InvalidArgumentException $exception) {
            // No notification rather than a fatal on the order status change.
            $this->logger->warning(
                'Centraliza notified statuses are not readable',
                ['path' => self::PATH_NOTIFIED_STATUSES, 'exception_class' => $exception::class]
            );

            return $this->notifiedStatusesCache[$cacheKey] = [];
        }

        if (!is_array($decoded)) {
            return $this->notifiedStatusesCache[$cacheKey] = [];
        }

        return $this->notifiedStatusesCache[$cacheKey] = $decoded;
    }
}
