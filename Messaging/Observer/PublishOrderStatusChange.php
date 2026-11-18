<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Observer;

use Centraliza\Connector\Messaging\Api\ConfigInterface as BaseConfigInterface;
use Centraliza\Connector\Messaging\Api\OrderNotificationConfigInterface;
use Centraliza\Connector\Messaging\Api\QueueOrderNotificationServiceInterface;
use Centraliza\Connector\Messaging\Model\CartRedirect\CartOrigin;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Throwable;

class PublishOrderStatusChange implements ObserverInterface
{
    /**
     * "<order id>:<from>:<to>" of the last transition handled: an order saved twice keeps its loaded status.
     * One value, so a long import cannot grow it; interleaved saves of two orders may queue one twice (rare).
     */
    private ?string $lastHandledTransition = null;

    /**
     * @param BaseConfigInterface $baseConfig
     * @param OrderNotificationConfigInterface $config
     * @param QueueOrderNotificationServiceInterface $queueOrderNotificationService
     * @param LoggerInterface $logger
     * @param CartOrigin $cartOrigin
     */
    public function __construct(
        private readonly BaseConfigInterface $baseConfig,
        private readonly OrderNotificationConfigInterface $config,
        private readonly QueueOrderNotificationServiceInterface $queueOrderNotificationService,
        private readonly LoggerInterface $logger,
        private readonly CartOrigin $cartOrigin
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');

        // Order, not OrderInterface: getOrigData() and getStore() are not on the service contract.
        if (!$order instanceof Order) {
            return;
        }

        try {
            $this->handle($order);
        } catch (Throwable $exception) {
            // Only adds the order and transition to the log: the save survives without this catch.
            $this->logger->error(
                'Centraliza notification could not be recorded for sending',
                [
                    'increment_id' => $order->getIncrementId(),
                    'from_status' => $order->getOrigData('status'),
                    'to_status' => $order->getStatus(),
                    'exception_class' => $exception::class,
                ]
            );
        }
    }

    /**
     * @param Order $order
     * @return void
     */
    private function handle(Order $order): void
    {
        $orderId = (int) $order->getEntityId();
        if (empty($orderId)) {
            // The send cron could not reload the order.
            return;
        }

        $toStatus = (string) $order->getStatus();
        if (empty($toStatus)) {
            return;
        }

        // origData is set by load() only: a new order's first status is notified with a null from_status.
        // Re-reading the row would not help: this runs after commit, so it already holds the new status.
        $fromStatus = $order->getOrigData('status');
        if ($fromStatus === $toStatus) {
            // A revert on the same instance (A to B, back to A) arrives as unchanged: it forgets the last
            // transition so the next A to B is notified again.
            if (str_starts_with((string) $this->lastHandledTransition, $orderId . ':')) {
                $this->lastHandledTransition = null;
            }

            return;
        }

        $transition = $orderId . ':' . (string) $fromStatus . ':' . $toStatus;
        if ($transition === $this->lastHandledTransition) {
            return;
        }

        if (!$this->notify($order, $orderId, $fromStatus, $toStatus)) {
            $this->lastHandledTransition = null;

            return;
        }

        $this->lastHandledTransition = $transition;
    }

    /**
     * Queue the transition when its website notifies it.
     *
     * @param Order $order
     * @param int $orderId
     * @param string|null $fromStatus
     * @param string $toStatus
     * @return bool false only when the notification was due but could not be queued
     */
    private function notify(Order $order, int $orderId, ?string $fromStatus, string $toStatus): bool
    {
        $websiteId = $this->resolveWebsiteId($order);
        if ($websiteId === null) {
            return false;
        }

        // Cheap guard first: isReady() decrypts the key, and this runs on every order save.
        if (!$this->config->isEnabled($websiteId) || !$this->baseConfig->isReady($websiteId)) {
            return true;
        }

        // A new order arrives with no previous status: its creation is queued before its first status, so
        // Centraliza gets it first. It does not depend on the notified statuses, which are for status events.
        if (empty($fromStatus)) {
            $this->queueCreatedFromLink($order, $orderId, $toStatus, $websiteId);
        }

        if (!$this->config->isStatusNotified($toStatus, $websiteId)) {
            return true;
        }

        return $this->queueOrderNotificationService->queue(
            $orderId,
            (string) $order->getIncrementId(),
            $fromStatus,
            $toStatus,
            $websiteId
        );
    }

    /**
     * Queue the order created event of an order whose cart a Centraliza link opened.
     *
     * @param Order $order
     * @param int $orderId
     * @param string $status
     * @param int $websiteId
     * @return void
     */
    private function queueCreatedFromLink(Order $order, int $orderId, string $status, int $websiteId): void
    {
        if (!$this->cartOrigin->isReportedToCentraliza($order)) {
            return;
        }

        // A failure is logged by the service; the status event still goes on.
        $this->queueOrderNotificationService->queueOrderCreated(
            $orderId,
            (string) $order->getIncrementId(),
            $status,
            $websiteId
        );
    }

    /**
     * Stored on the row because the send cron runs in CLI with no store context.
     *
     * @param Order $order
     * @return int|null
     */
    private function resolveWebsiteId(Order $order): ?int
    {
        try {
            return (int) $order->getStore()->getWebsiteId();
        } catch (Throwable $exception) {
            $this->logger->warning(
                'Centraliza notification could not resolve the order website',
                [
                    'increment_id' => $order->getIncrementId(),
                    'store_id' => $order->getStoreId(),
                    'exception_class' => $exception::class,
                ]
            );

            return null;
        }
    }
}
