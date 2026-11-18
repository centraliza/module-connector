<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Service\OrderNotification;

use Centraliza\Connector\Messaging\Api\ResendNotificationServiceInterface;
use Centraliza\Connector\Messaging\Api\SendNotificationServiceInterface;
use Centraliza\Connector\Messaging\Model\OrderNotification\Notification;
use Centraliza\Connector\Messaging\Model\OrderNotification\ResendSummary;
use Centraliza\Connector\Messaging\Model\ResourceModel\OrderNotification\Notification as NotificationResource;

class ResendNotificationService implements ResendNotificationServiceInterface
{
    /**
     * Bounds the IN list and the rows locked by each UPDATE.
     */
    private const BATCH_SIZE = 500;

    private const RESENDABLE = 'resendable';

    private const NOT_RESENDABLE = 'not_resendable';

    private const EXPIRED = 'expired';

    private const PERMANENT_ERROR = 'permanent_error';

    /**
     * @param NotificationResource $notificationResource
     * @param SendNotificationServiceInterface $sendService
     */
    public function __construct(
        private readonly NotificationResource $notificationResource,
        private readonly SendNotificationServiceInterface $sendService
    ) {
    }

    /**
     * @param int[] $ids
     * @return ResendSummary
     */
    public function resend(array $ids): ResendSummary
    {
        $counts = [self::RESENDABLE => 0, self::NOT_RESENDABLE => 0, self::EXPIRED => 0, self::PERMANENT_ERROR => 0];
        $resent = 0;

        foreach (array_chunk(array_unique(array_map('intval', $ids)), self::BATCH_SIZE) as $batch) {
            $resendableIds = [];
            foreach ($this->notificationResource->getResendCandidates($batch) as $row) {
                $reason = $this->classify($row);
                $counts[$reason]++;
                if ($reason === self::RESENDABLE) {
                    $resendableIds[] = (int) $row['entity_id'];
                }
            }

            $resent += $this->notificationResource->scheduleResend($resendableIds);
        }

        // A row the cron or a callback moved out of failed/not_sent between the read and the
        // update is not resent, and is reported with the rows that were not resendable.
        return new ResendSummary(
            $resent,
            $counts[self::NOT_RESENDABLE] + $counts[self::RESENDABLE] - $resent,
            $counts[self::EXPIRED],
            $counts[self::PERMANENT_ERROR]
        );
    }

    /**
     * @param array $row
     * @return string
     */
    private function classify(array $row): string
    {
        $status = (string) $row['status'];

        if (!in_array($status, Notification::RESENDABLE_STATUSES, true)) {
            return self::NOT_RESENDABLE;
        }

        // Holds for a manual resend too: the cron would fail an expired row without a request anyway.
        if ($this->sendService->isPastSendWindow((string) $row['created_at'], (int) $row['website_id'])) {
            return self::EXPIRED;
        }

        // Not empty(): a stored 0 is a request that was never built and fails again; null left no code.
        if ($status === Notification::STATUS_FAILED
            && $row['last_error_code'] !== null
            && !in_array((int) $row['last_error_code'], Notification::RETRYABLE_CODES, true)
        ) {
            return self::PERMANENT_ERROR;
        }

        return self::RESENDABLE;
    }
}
