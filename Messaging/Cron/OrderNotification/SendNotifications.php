<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Cron\OrderNotification;

use Centraliza\Connector\Messaging\Api\ApiKeyGroupingInterface;
use Centraliza\Connector\Messaging\Api\CentralizaClientInterface;
use Centraliza\Connector\Messaging\Api\KeyLockInterface;
use Centraliza\Connector\Messaging\Api\RotationInterface;
use Centraliza\Connector\Messaging\Api\StoreScopeInterface;
use Centraliza\Connector\Messaging\Exception\CentralizaApiException;
use Centraliza\Connector\Messaging\Api\OrderNotificationConfigInterface;
use Centraliza\Connector\Messaging\Api\SendNotificationServiceInterface;
use Centraliza\Connector\Messaging\Model\ResourceModel\OrderNotification\Notification as NotificationResource;
use Error;
use Magento\Framework\Stdlib\DateTime as DateTimeFormat;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;
use Throwable;

class SendNotifications
{
    /**
     * One lock per key, named after its hash (never the key): two processes must not spend its quota at once.
     */
    private const KEY_LOCK_PREFIX = 'centraliza_notification_send_';

    /**
     * crontab.xml runs this job every minute.
     */
    private const RUN_INTERVAL_MINUTES = 1;

    private const SECONDS_PER_MINUTE = 60;

    /**
     * The dead process may have sent the POST, so the row is never resent automatically.
     */
    private const EXPIRED_RESERVATION_MESSAGE =
        'Outcome unknown: the process stopped after reserving the row; the request may have been sent';

    private const WEBSITE_GONE_MESSAGE = 'Website no longer exists';

    /**
     * @param NotificationResource $notificationResource
     * @param SendNotificationServiceInterface $sendNotificationService
     * @param OrderNotificationConfigInterface $config
     * @param StoreScopeInterface $storeScope
     * @param ApiKeyGroupingInterface $apiKeyGrouping
     * @param RotationInterface $rotation
     * @param KeyLockInterface $keyLock
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly NotificationResource $notificationResource,
        private readonly SendNotificationServiceInterface $sendNotificationService,
        private readonly OrderNotificationConfigInterface $config,
        private readonly StoreScopeInterface $storeScope,
        private readonly ApiKeyGroupingInterface $apiKeyGrouping,
        private readonly RotationInterface $rotation,
        private readonly KeyLockInterface $keyLock,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        $now = $this->dateTime->gmtTimestamp();
        $this->releaseExpiredReservations($now);

        try {
            $readyWebsiteIds = $this->notificationResource->getWebsiteIdsReadyToSend(
                $this->dateTime->gmtDate(DateTimeFormat::DATETIME_PHP_FORMAT, $now)
            );
        } catch (Throwable $exception) {
            $this->logFailure(
                'Centraliza notification ready websites could not be read; nothing sent this round',
                [],
                $exception
            );

            return;
        }

        $enabledWebsiteIds = [];
        $skippedWebsiteIds = [];
        foreach ($readyWebsiteIds as $websiteId) {
            // Existence first: reading the settings of a deleted website throws.
            try {
                $websiteExists = $this->storeScope->websiteExists($websiteId);
                $isEnabled = $websiteExists && $this->config->isEnabled($websiteId);
            } catch (Throwable $exception) {
                $this->logFailure(
                    'Centraliza notification website could not be read; left out of this round',
                    ['website_id' => $websiteId],
                    $exception
                );
                $skippedWebsiteIds[] = $websiteId;

                continue;
            }

            // A deleted website never sends again, so its waiting rows are failed now.
            if (!$websiteExists) {
                $this->failGoneWebsiteRows($websiteId, $now);

                continue;
            }

            if (!$isEnabled) {
                $skippedWebsiteIds[] = $websiteId;

                continue;
            }

            $enabledWebsiteIds[] = $websiteId;
        }

        // Quota and credential belong to the key, so the round is served per key; websites whose
        // key is not ready are left out by the Base and skipped like the disabled ones.
        $websiteIdsByKey = $this->apiKeyGrouping->groupWebsitesByApiKey($enabledWebsiteIds);
        $groupedWebsiteIds = array_merge([], ...array_values($websiteIdsByKey));
        $skippedWebsiteIds = [...$skippedWebsiteIds, ...array_diff($enabledWebsiteIds, $groupedWebsiteIds)];

        // A skipped website has no row reserved, so its rows past the window expire here.
        foreach ($skippedWebsiteIds as $websiteId) {
            try {
                $this->sendNotificationService->failRowsPastSendWindow($websiteId);
            } catch (Throwable $exception) {
                $this->logFailure(
                    'Centraliza notification rows of a skipped website could not be expired',
                    ['website_id' => $websiteId],
                    $exception
                );
            }
        }

        $apiKeyHashes = $this->rotation->rotate(array_keys($websiteIdsByKey), self::RUN_INTERVAL_MINUTES);
        foreach ($apiKeyHashes as $position => $apiKeyHash) {
            try {
                $this->sendWithKey(
                    $position,
                    (string) $apiKeyHash,
                    $this->rotation->rotate($websiteIdsByKey[$apiKeyHash], self::RUN_INTERVAL_MINUTES)
                );
            } catch (CentralizaApiException) {
                // The API is the same for every key: the others would only wait out the same outage.
                $this->logger->warning('Centraliza notification round ended: Centraliza is unavailable');

                return;
            }
        }
    }

    /**
     * Send one key's ready rows under its lock.
     *
     * @param int $position
     * @param string $apiKeyHash
     * @param int[] $websiteIds
     * @return void
     * @throws CentralizaApiException when Centraliza is down or unreachable
     */
    private function sendWithKey(int $position, string $apiKeyHash, array $websiteIds): void
    {
        $lockName = self::KEY_LOCK_PREFIX . $apiKeyHash;
        $context = ['key_position' => $position, 'website_ids' => $websiteIds];

        if (!$this->keyLock->acquire($lockName)) {
            $this->logger->info('Centraliza notification key skipped: another process is sending with it', $context);

            return;
        }

        try {
            $this->sendNotificationService->sendReadyRows($websiteIds);
        } catch (CentralizaApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // One key's unexpected error, such as a deadlock while reserving, must not stop the other keys.
            $this->logFailure('Centraliza notification key turn failed', $context, $exception);
        } finally {
            $this->keyLock->release($lockName);
        }
    }

    /**
     * Mark unknown the rows left in sending by a process that died, one row at a time.
     *
     * @param int $now
     * @return void
     */
    private function releaseExpiredReservations(int $now): void
    {
        $expiredBefore = $this->dateTime->gmtDate(
            DateTimeFormat::DATETIME_PHP_FORMAT,
            $now - CentralizaClientInterface::RESERVATION_TIMEOUT_MINUTES * self::SECONDS_PER_MINUTE
        );

        // A lock-wait timeout here must not skip the round: the ready rows are still sent.
        try {
            $expiredReservations = $this->notificationResource->findExpiredReservations($expiredBefore);
        } catch (Throwable $exception) {
            $this->logFailure('Centraliza notification expired reservations could not be read', [], $exception);

            return;
        }

        $released = 0;
        $websiteIds = [];
        foreach ($expiredReservations as $row) {
            try {
                $written = $this->notificationResource->markExpiredReservationUnknown(
                    (int) $row['entity_id'],
                    (int) $row['attempts'],
                    $expiredBefore,
                    self::EXPIRED_RESERVATION_MESSAGE
                );
            } catch (Throwable $exception) {
                $this->logger->error(
                    'Centraliza notification expired reservation could not be released',
                    ['notification_id' => (int) $row['entity_id'], 'exception_class' => $exception::class]
                );

                continue;
            }

            if ($written) {
                $released++;
                $websiteIds[] = (int) $row['website_id'];
            }
        }

        if (empty($released)) {
            return;
        }

        $this->logger->warning(
            'Centraliza notification rows released from expired reservations were marked unknown',
            ['website_ids' => array_values(array_unique($websiteIds)), 'rows' => $released]
        );
    }

    /**
     * Fail every waiting row of a website that no longer exists, without a request.
     *
     * @param int $websiteId
     * @param int $now
     * @return void
     */
    private function failGoneWebsiteRows(int $websiteId, int $now): void
    {
        try {
            $failed = $this->notificationResource->failWaitingCreatedBefore(
                $websiteId,
                $this->dateTime->gmtDate(DateTimeFormat::DATETIME_PHP_FORMAT, $now),
                self::WEBSITE_GONE_MESSAGE
            );
        } catch (Throwable $exception) {
            $this->logFailure(
                'Centraliza notification rows of a deleted website could not be failed',
                ['website_id' => $websiteId],
                $exception
            );

            return;
        }

        $this->logger->warning(
            'Centraliza notification website no longer exists; its waiting rows were failed',
            ['website_id' => $websiteId, 'rows' => $failed]
        );
    }

    /**
     * Log a failure by class and origin only; a PHP \Error is a defect, so it is an error.
     *
     * @param string $message
     * @param array $context
     * @param Throwable $exception
     * @return void
     */
    private function logFailure(string $message, array $context, Throwable $exception): void
    {
        $context += [
            'exception_class' => $exception::class,
            'origin' => $exception->getFile() . ':' . $exception->getLine(),
        ];
        if ($exception instanceof Error) {
            $this->logger->error($message, $context);

            return;
        }

        $this->logger->warning($message, $context);
    }
}
