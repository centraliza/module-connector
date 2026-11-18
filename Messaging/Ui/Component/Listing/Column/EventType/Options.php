<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Ui\Component\Listing\Column\EventType;

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
            ['value' => Notification::EVENT_ORDER_CREATED, 'label' => __('Order created')],
            ['value' => Notification::EVENT_ORDER_STATUS_UPDATED, 'label' => __('Order status updated')],
        ];
    }
}
