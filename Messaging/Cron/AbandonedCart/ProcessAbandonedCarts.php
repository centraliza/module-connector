<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Cron\AbandonedCart;

use Centraliza\Connector\Messaging\Api\ApiKeyGroupingInterface;
use Centraliza\Connector\Messaging\Api\KeyLockInterface;
use Centraliza\Connector\Messaging\Api\RotationInterface;
use Centraliza\Connector\Messaging\Api\StoreScopeInterface;
use Centraliza\Connector\Messaging\Exception\CentralizaApiException;
use Centraliza\Connector\Messaging\Api\AbandonedCartConfigInterface;
use Centraliza\Connector\Messaging\Api\SelectAbandonedCartsServiceInterface;
use Centraliza\Connector\Messaging\Api\SendAbandonedCartsServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class ProcessAbandonedCarts
{
    /**
     * One lock per key, named after its hash (never the key): two runs must not spend its quota at once.
     */
    private const KEY_LOCK_PREFIX = 'centraliza_sales_abandoned_cart_send_';

    /**
     * Must match the every-minute schedule of etc/crontab.xml.
     */
    private const RUN_INTERVAL_MINUTES = 1;

    /**
     * @param StoreScopeInterface $storeScope
     * @param ApiKeyGroupingInterface $apiKeyGrouping
     * @param RotationInterface $rotation
     * @param AbandonedCartConfigInterface $config
     * @param SelectAbandonedCartsServiceInterface $selectService
     * @param SendAbandonedCartsServiceInterface $sendService
     * @param KeyLockInterface $keyLock
     * @param LoggerInterface $logger
     * @param LoggerInterface $debugLogger written only while the Base Debug Log is on
     */
    public function __construct(
        private readonly StoreScopeInterface $storeScope,
        private readonly ApiKeyGroupingInterface $apiKeyGrouping,
        private readonly RotationInterface $rotation,
        private readonly AbandonedCartConfigInterface $config,
        private readonly SelectAbandonedCartsServiceInterface $selectService,
        private readonly SendAbandonedCartsServiceInterface $sendService,
        private readonly KeyLockInterface $keyLock,
        private readonly LoggerInterface $logger,
        private readonly LoggerInterface $debugLogger
    ) {
    }

    /**
     * Release expired reservations, then select and send the abandoned carts of every active website.
     *
     * @return void
     */
    public function execute(): void
    {
        $released = $this->releaseExpiredReservations();
        $websiteIds = $this->getActiveWebsiteIds();

        $selected = 0;
        foreach ($websiteIds as $websiteId) {
            $selected += $this->selectAbandonedCarts($websiteId);
        }

        // The quota belongs to the key, so websites sharing a key are sent in one round. Keys and
        // websites rotate by run, so a capped round does not always serve the same ones first.
        $websiteIdsByKey = $this->apiKeyGrouping->groupWebsitesByApiKey($websiteIds);
        $requests = 0;
        foreach ($this->rotation->rotate(array_keys($websiteIdsByKey), self::RUN_INTERVAL_MINUTES) as $apiKeyHash) {
            try {
                $requests += $this->sendAbandonedCarts(
                    (string) $apiKeyHash,
                    $this->rotation->rotate($websiteIdsByKey[$apiKeyHash], self::RUN_INTERVAL_MINUTES)
                );
            } catch (CentralizaApiException) {
                // The API is the same for every key: the others would only wait out the same outage.
                $this->logger->warning('Centraliza abandoned cart run ended: Centraliza is unavailable');
                break;
            }
        }

        $this->debugLogger->info('Centraliza abandoned cart run', [
            'released' => $released,
            'selected' => $selected,
            'requests' => $requests,
        ]);
    }

    /**
     * @return int
     */
    private function releaseExpiredReservations(): int
    {
        try {
            return $this->sendService->releaseExpiredReservations();
        } catch (Throwable $exception) {
            $this->logger->error(
                'Centraliza abandoned cart expired-reservation release failed',
                ['exception_class' => $exception::class]
            );

            return 0;
        }
    }

    /**
     * Websites where this feature is enabled and the Base holds a validated key.
     *
     * @return int[]
     */
    private function getActiveWebsiteIds(): array
    {
        $activeWebsiteIds = [];
        foreach ($this->storeScope->getWebsiteIds() as $websiteId) {
            try {
                if ($this->config->isActive($websiteId)) {
                    $activeWebsiteIds[] = $websiteId;
                }
            } catch (Throwable $exception) {
                $this->logger->warning(
                    'Centraliza abandoned cart website could not be read; left out of this run',
                    ['website_id' => $websiteId, 'exception_class' => $exception::class]
                );
            }
        }

        return $activeWebsiteIds;
    }

    /**
     * @param int $websiteId
     * @return int how many carts were selected
     */
    private function selectAbandonedCarts(int $websiteId): int
    {
        try {
            return $this->selectService->execute($websiteId);
        } catch (Throwable $exception) {
            // A failure on one website must not stop the selection and the sending of the others.
            $this->logger->error(
                'Centraliza abandoned cart selection failed',
                ['website_id' => $websiteId, 'exception_class' => $exception::class]
            );

            return 0;
        }
    }

    /**
     * @param string $apiKeyHash
     * @param int[] $websiteIds
     * @return int requests made
     * @throws CentralizaApiException when Centraliza is down or unreachable
     */
    private function sendAbandonedCarts(string $apiKeyHash, array $websiteIds): int
    {
        $lockName = self::KEY_LOCK_PREFIX . $apiKeyHash;

        if (!$this->keyLock->acquire($lockName)) {
            $this->logger->info('Centraliza abandoned cart key skipped: another run holds it', [
                'website_ids' => $websiteIds,
            ]);

            return 0;
        }

        try {
            return $this->sendService->send($websiteIds);
        } catch (CentralizaApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->error(
                'Centraliza abandoned cart send round failed',
                ['website_ids' => $websiteIds, 'exception_class' => $exception::class]
            );

            return 0;
        } finally {
            $this->keyLock->release($lockName);
        }
    }
}
