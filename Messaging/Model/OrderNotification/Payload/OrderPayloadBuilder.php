<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\OrderNotification\Payload;

use Centraliza\Connector\Messaging\Api\PhoneNumberFormatterInterface;
use Centraliza\Connector\Messaging\Api\OrderPayloadBuilderInterface;
use Centraliza\Connector\Messaging\Model\CartRedirect\CartOrigin;
use Centraliza\Connector\Messaging\Model\OrderNotification\Notification;
use Centraliza\Connector\Messaging\Model\Payload\CustomerWhatsapp;
use Magento\Framework\App\Area;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\StatusLabel;
use Magento\Store\Model\App\Emulation;
use Psr\Log\LoggerInterface;

class OrderPayloadBuilder implements OrderPayloadBuilderInterface
{
    /**
     * Centraliza event contract: the event type of an order status change on the events route.
     */
    private const EVENT_TYPE = Notification::EVENT_ORDER_STATUS_UPDATED;

    /**
     * @param StatusLabel $statusLabel
     * @param PhoneNumberFormatterInterface $phoneNumberFormatter
     * @param UrlInterface $urlBuilder
     * @param LoggerInterface $logger
     * @param Emulation $emulation
     * @param CartOrigin $cartOrigin
     * @param CustomerWhatsapp $customerWhatsapp
     */
    public function __construct(
        private readonly StatusLabel $statusLabel,
        private readonly PhoneNumberFormatterInterface $phoneNumberFormatter,
        private readonly UrlInterface $urlBuilder,
        private readonly LoggerInterface $logger,
        private readonly Emulation $emulation,
        private readonly CartOrigin $cartOrigin,
        private readonly CustomerWhatsapp $customerWhatsapp
    ) {
    }

    /**
     * @param OrderInterface $order
     * @param string $toStatus
     * @return array<string, mixed>
     */
    public function build(OrderInterface $order, string $toStatus): array
    {
        $orderId = (int) $order->getEntityId();
        $incrementId = (string) $order->getIncrementId();

        $payload = [
            'type' => self::EVENT_TYPE,
            // One event per order and status: the same status change is never notified twice.
            'idempotency_key' => 'order-' . $orderId . '-' . $toStatus,
            'contact' => [
                'name' => $this->getCustomerName($order),
                'phone' => $this->getCustomerPhone($order, $incrementId),
                'email' => (string) $order->getCustomerEmail(),
                // Fixed true while the consent source is being decided, so test stores can follow the flow. It
                // reports consent the customer may not have given: not for a production store (LGPD).
                'marketing_opt_in' => true,
            ],
            'data' => [
                'order' => [
                    'id' => (string) $orderId,
                    'number' => $incrementId,
                    'status' => $toStatus,
                    'status_label' => $this->getStatusLabel($order, $toStatus),
                    'total' => round((float) $order->getGrandTotal(), 2),
                    'currency' => (string) $order->getOrderCurrencyCode(),
                    'url' => $this->getOrderUrl($order, $orderId),
                ],
            ],
        ];

        // Every event of an order that came from a Centraliza link names the cart Centraliza knows it by;
        // the store's own orders keep the event as it was.
        $cart = $this->getCart($order);
        if (!empty($cart)) {
            $payload['data']['cart'] = $cart;
        }

        return $payload;
    }

    /**
     * Cart Centraliza knows the order by, or an empty array for an order that did not come from its link.
     *
     * @param OrderInterface $order
     * @return array<string, string>
     */
    private function getCart(OrderInterface $order): array
    {
        // The store is read from the order model; a status event Centraliza refuses for the extra field fails for good.
        if (!$order instanceof Order || !$this->cartOrigin->isReportedToCentraliza($order)) {
            return [];
        }

        return [
            'id' => (string) $order->getQuoteId(),
            'masked_id' => $this->cartOrigin->getOrderMaskedId($order),
        ];
    }

    /**
     * @param OrderInterface $order
     * @return string
     */
    private function getCustomerName(OrderInterface $order): string
    {
        $billingAddress = $order->getBillingAddress();
        if (empty($billingAddress)) {
            return '';
        }

        // Order\Address::getName() is off the service contract and leaves a stray space when a part is missing.
        return trim(
            sprintf('%s %s', (string) $billingAddress->getFirstname(), (string) $billingAddress->getLastname())
        );
    }

    /**
     * E.164 with the plus, or empty when the order has no usable telephone.
     *
     * @param OrderInterface $order
     * @param string $incrementId
     * @return string
     */
    private function getCustomerPhone(OrderInterface $order, string $incrementId): string
    {
        $billingAddress = $order->getBillingAddress();

        // The customer's WhatsApp first: it is the number the registration asks to be messaged on. A guest
        // order, or one without a usable WhatsApp, falls back to the billing telephone. The WhatsApp keeps
        // no country of its own, so it is read in the billing address's, as the telephone is.
        $whatsapp = $this->phoneNumberFormatter->format(
            $this->customerWhatsapp->getOrderWhatsapp($order),
            $billingAddress?->getCountryId()
        );
        if (!empty($whatsapp)) {
            return '+' . $whatsapp;
        }

        if (empty($billingAddress)) {
            return '';
        }

        $phone = $this->phoneNumberFormatter->format($billingAddress->getTelephone(), $billingAddress->getCountryId());
        if (empty($phone)) {
            // Sent empty rather than as a guess: Centraliza accepts a malformed number. Never logged.
            $this->logger->warning(
                'Centraliza notification discarded an unusable customer telephone',
                ['increment_id' => $incrementId, 'country_id' => $billingAddress->getCountryId()]
            );

            return '';
        }

        return '+' . $phone;
    }

    /**
     * The label, not the code: the customer reads "Enviado", not "complete".
     *
     * @param OrderInterface $order
     * @param string $status
     * @return string
     */
    private function getStatusLabel(OrderInterface $order, string $status): string
    {
        $storeId = (int) $order->getStoreId();

        // A status without a store view label falls back to its translated base label. The store's
        // environment makes that the store's language, not the admin user's one when sent from Send now.
        try {
            $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
            $label = (string) $this->statusLabel->getStatusFrontendLabel($status, Area::AREA_FRONTEND, $storeId);
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }

        // A status deleted since the row was queued has no label: the code beats a blank status.
        if (empty($label)) {
            return $status;
        }

        return $label;
    }

    /**
     * Order page in the customer account, on the order's store view.
     *
     * @param OrderInterface $order
     * @param int $orderId
     * @return string
     */
    private function getOrderUrl(OrderInterface $order, int $orderId): string
    {
        // Scope per call, never setScope() on the shared builder: the store would stick to every later URL of
        // the process. Secure: the cron does not load the frontend list of secure paths.
        return $this->urlBuilder->getUrl(
            'sales/order/view',
            ['order_id' => $orderId, '_scope' => (int) $order->getStoreId(), '_secure' => true]
        );
    }
}
