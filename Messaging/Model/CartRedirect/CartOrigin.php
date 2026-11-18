<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\CartRedirect;

use Centraliza\Connector\Messaging\Api\OrderNotificationConfigInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;

/**
 * Masked id of the Centraliza link that opened a cart, on the quote and its order; empty for the store's own.
 */
class CartOrigin
{
    /**
     * Column on quote and sales_order, copied to the order by the sales_convert_quote fieldset.
     */
    public const FIELD = 'centraliza_masked_id';

    /**
     * @param OrderNotificationConfigInterface $orderNotificationConfig
     */
    public function __construct(
        private readonly OrderNotificationConfigInterface $orderNotificationConfig
    ) {
    }

    /**
     * @param CartInterface $quote
     * @return string
     */
    public function getQuoteMaskedId(CartInterface $quote): string
    {
        if (!$quote instanceof Quote) {
            return '';
        }

        return (string) $quote->getData(self::FIELD);
    }

    /**
     * @param OrderInterface $order
     * @return string
     */
    public function getOrderMaskedId(OrderInterface $order): string
    {
        // The column is not on the service contracts: only the quote and order models carry it.
        if (!$order instanceof Order) {
            return '';
        }

        return (string) $order->getData(self::FIELD);
    }

    /**
     * Whether Centraliza is told about this order: it came from a link and the website releases the events.
     *
     * @param Order $order
     * @return bool
     */
    public function isReportedToCentraliza(Order $order): bool
    {
        // Only an order that came from a Centraliza link is announced; any other order is the store's own.
        if (empty($this->getOrderMaskedId($order))) {
            return false;
        }

        // Released per website: the event contract is not confirmed by Centraliza yet.
        return $this->orderNotificationConfig->isOrderCreatedEnabled((int) $order->getStore()->getWebsiteId());
    }

    /**
     * @param CartInterface $quote
     * @param string $maskedId
     * @return void
     */
    public function markQuote(CartInterface $quote, string $maskedId): void
    {
        // The first link that opened the cart stays its id: Centraliza already knows the cart by it.
        if (!$quote instanceof Quote || empty($maskedId) || !empty($this->getQuoteMaskedId($quote))) {
            return;
        }

        $quote->setData(self::FIELD, $maskedId);
    }

    /**
     * @param CartInterface $quote
     * @param OrderInterface $order
     * @return void
     */
    public function copyToOrder(CartInterface $quote, OrderInterface $order): void
    {
        $maskedId = $this->getQuoteMaskedId($quote);
        if (empty($maskedId) || !$order instanceof Order) {
            return;
        }

        $order->setData(self::FIELD, $maskedId);
    }
}
