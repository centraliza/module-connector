<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api;

use Magento\Sales\Api\Data\OrderInterface;

/**
 * Builds the order.created event Centraliza receives for an order that came from its cart link.
 */
interface OrderCreatedPayloadBuilderInterface
{
    /**
     * @param OrderInterface $order
     * @param string $status the order status when it was created, as queued
     * @return array<string, mixed> the event, or an empty array when the order cannot be described
     */
    public function build(OrderInterface $order, string $status): array;
}
