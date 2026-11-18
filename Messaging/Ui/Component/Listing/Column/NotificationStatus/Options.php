<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Ui\Component\Listing\Column\NotificationStatus;

use Centraliza\Connector\Messaging\Model\OrderNotification\Notification;
use Magento\Framework\Data\OptionSourceInterface;

class Options implements OptionSourceInterface
{
    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Notification::STATUS_PENDING, 'label' => __('Pending')],
            ['value' => Notification::STATUS_SENDING, 'label' => __('Sending')],
            ['value' => Notification::STATUS_RETRY, 'label' => __('Retry scheduled')],
            ['value' => Notification::STATUS_DELIVERED, 'label' => __('Accepted by Centraliza')],
            ['value' => Notification::STATUS_SENT, 'label' => __('Sent')],
            ['value' => Notification::STATUS_NOT_SENT, 'label' => __('Not sent')],
            ['value' => Notification::STATUS_FAILED, 'label' => __('Failed')],
            ['value' => Notification::STATUS_UNKNOWN, 'label' => __('Outcome unknown')],
        ];
    }
}
