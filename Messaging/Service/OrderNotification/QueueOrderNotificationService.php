<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Service\OrderNotification;

use Centraliza\Connector\Messaging\Api\QueueOrderNotificationServiceInterface;
use Centraliza\Connector\Messaging\Model\OrderNotification\Notification;
use Centraliza\Connector\Messaging\Model\ResourceModel\OrderNotification\Notification as NotificationResource;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\DB\Adapter\DuplicateException;
use Psr\Log\LoggerInterface;
use Throwable;

class QueueOrderNotificationService implements QueueOrderNotificationServiceInterface
{
    /**
     * @param NotificationResource $notificationResource
     * @param IdentityGeneratorInterface $identityGenerator
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly NotificationResource $notificationResource,
        private readonly IdentityGeneratorInterface $identityGenerator,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param int $orderId
     * @param string $incrementId
     * @param string|null $fromStatus
     * @param string $toStatus
     * @param int $websiteId
     * @return bool
     */
    public function queue(
        int $orderId,
        string $incrementId,
        ?string $fromStatus,
        string $toStatus,
        int $websiteId
    ): bool {
        try {
            $this->notificationResource->insertPending([
                'event_id' => $this->identityGenerator->generateId(),
                'event_type' => Notification::EVENT_ORDER_STATUS_UPDATED,
                'order_id' => $orderId,
                'increment_id' => $incrementId,
                'website_id' => $websiteId,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
            ]);
        } catch (Throwable $exception) {
            // This runs after the order was committed: throwing here would fail the screen
            // of whoever saved the order because of a notification.
            $this->logger->error(
                'Centraliza notification could not be queued',
                ['increment_id' => $incrementId, 'to_status' => $toStatus, 'exception_class' => $exception::class]
            );

            return false;
        }

        return true;
    }

    /**
     * @param int $orderId
     * @param string $incrementId
     * @param string $status
     * @param int $websiteId
     * @return void
     */
    public function queueOrderCreated(int $orderId, string $incrementId, string $status, int $websiteId): void
    {
        try {
            // An order is created once: its event id is fixed, so the unique event_id refuses a second row from a
            // new order saved again in the same request, or from another process.
            $this->notificationResource->insertPending([
                'event_id' => 'order-' . $orderId . '-created',
                'event_type' => Notification::EVENT_ORDER_CREATED,
                'order_id' => $orderId,
                'increment_id' => $incrementId,
                'website_id' => $websiteId,
                'to_status' => $status,
            ]);
        } catch (DuplicateException) {
            // Already queued by an earlier save of the same new order.
        } catch (Throwable $exception) {
            // This runs after the order was committed: throwing here would fail the screen of whoever placed it.
            $this->logger->error(
                'Centraliza order created notification could not be queued',
                ['increment_id' => $incrementId, 'exception_class' => $exception::class]
            );
        }
    }
}
