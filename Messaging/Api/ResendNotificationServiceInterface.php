<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api;

use Centraliza\Connector\Messaging\Model\OrderNotification\ResendSummary;

/**
 * Resend, on an administrator's request, the notifications that can still be sent.
 */
interface ResendNotificationServiceInterface
{
    /**
     * Schedule the resendable ones among the given notifications and report what happened to the rest.
     *
     * @param int[] $ids
     * @return ResendSummary
     */
    public function resend(array $ids): ResendSummary;
}
