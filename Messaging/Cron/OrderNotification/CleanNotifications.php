<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Cron\OrderNotification;

use Centraliza\Connector\Messaging\Model\ResourceModel\OrderNotification\Notification as NotificationResource;
use Magento\Framework\Stdlib\DateTime as DateTimeFormat;
use Magento\Framework\Stdlib\DateTime\DateTime;

class CleanNotifications
{
    /**
     * Placeholder until the PO sets the retention; a callback is no longer expected after it either.
     */
    public const RETENTION_DAYS = 90;

    /**
     * Small batches keep each delete transaction short while the send cron uses the table.
     */
    private const BATCH_SIZE = 1000;

    /**
     * @param NotificationResource $notificationResource
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly NotificationResource $notificationResource,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        $before = $this->dateTime->gmtDate(
            DateTimeFormat::DATETIME_PHP_FORMAT,
            $this->dateTime->gmtTimestamp() - self::RETENTION_DAYS * 86400
        );

        while ($this->notificationResource->deleteFinishedBefore($before, self::BATCH_SIZE) === self::BATCH_SIZE) {
            continue;
        }

        while ($this->notificationResource->deleteDeliveredBefore($before, self::BATCH_SIZE) === self::BATCH_SIZE) {
            continue;
        }
    }
}
