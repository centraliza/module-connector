<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Ui\Component\Listing\Column\AbandonedCartStatus;

use Centraliza\Connector\Messaging\Api\Data\AbandonedCartInterface;
use Magento\Framework\Data\OptionSourceInterface;

class Options implements OptionSourceInterface
{
    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => AbandonedCartInterface::STATUS_PENDING, 'label' => __('Pending')],
            ['value' => AbandonedCartInterface::STATUS_PROCESSING, 'label' => __('Sending')],
            ['value' => AbandonedCartInterface::STATUS_RETRY, 'label' => __('Retry scheduled')],
            ['value' => AbandonedCartInterface::STATUS_SUCCESS, 'label' => __('Sent to Centraliza')],
            ['value' => AbandonedCartInterface::STATUS_FAILED, 'label' => __('Failed')],
        ];
    }
}
