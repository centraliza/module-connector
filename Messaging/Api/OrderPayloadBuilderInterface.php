<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api;

use Magento\Sales\Api\Data\OrderInterface;

/**
 * Builds the order.status_updated event Centraliza receives for an order status change.
 */
interface OrderPayloadBuilderInterface
{
    /**
     * @param OrderInterface $order
     * @param string $toStatus the Magento status code the order changed to
     * @return array<string, mixed> the event, with the contact and the order
     */
    public function build(OrderInterface $order, string $toStatus): array;
}
