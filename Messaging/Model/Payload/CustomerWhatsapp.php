<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Payload;

use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;

/**
 * The customer's WhatsApp on the cart and the order, tried before the address telephones: it is the number the
 * store's registration asks to be messaged on.
 */
class CustomerWhatsapp
{
    /**
     * Column on quote and sales_order that Caravel_CompanyWhatsapp fills from the customer attribute
     * company_whatsapp, as Magento does with customer_taxvat. This module only reads it: without that module, or on
     * a guest's cart and order, it is empty and the address telephones are used.
     */
    private const FIELD = 'customer_whatsapp';

    /**
     * @param CartInterface $quote
     * @return string|null
     */
    public function getQuoteWhatsapp(CartInterface $quote): ?string
    {
        // The column is not on the service contracts: only the quote and order models carry it.
        if (!$quote instanceof Quote) {
            return null;
        }

        $whatsapp = $quote->getData(self::FIELD);

        return is_string($whatsapp) ? $whatsapp : null;
    }

    /**
     * @param OrderInterface $order
     * @return string|null
     */
    public function getOrderWhatsapp(OrderInterface $order): ?string
    {
        if (!$order instanceof Order) {
            return null;
        }

        $whatsapp = $order->getData(self::FIELD);

        return is_string($whatsapp) ? $whatsapp : null;
    }
}
