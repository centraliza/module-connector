<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\OrderNotification\Payload;

use Centraliza\Connector\Messaging\Api\PhoneNumberFormatterInterface;
use Centraliza\Connector\Messaging\Api\OrderCreatedPayloadBuilderInterface;
use Centraliza\Connector\Messaging\Api\OrderPayloadBuilderInterface;
use Centraliza\Connector\Messaging\Model\OrderNotification\Notification;
use Centraliza\Connector\Messaging\Model\Payload\QuantityFormatter;
use DateTimeImmutable;
use DateTimeZone;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;

class OrderCreatedPayloadBuilder implements OrderCreatedPayloadBuilderInterface
{
    /**
     * Centraliza event contract (proposed, not confirmed by Centraliza yet): dates in ISO 8601, UTC.
     */
    private const DATE_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * @param OrderPayloadBuilderInterface $orderPayloadBuilder
     * @param PhoneNumberFormatterInterface $phoneNumberFormatter
     * @param QuantityFormatter $quantityFormatter
     */
    public function __construct(
        private readonly OrderPayloadBuilderInterface $orderPayloadBuilder,
        private readonly PhoneNumberFormatterInterface $phoneNumberFormatter,
        private readonly QuantityFormatter $quantityFormatter
    ) {
    }

    /**
     * @param OrderInterface $order
     * @param string $status
     * @return array<string, mixed>
     */
    public function build(OrderInterface $order, string $status): array
    {
        // Items, shipping address and store are read from the order model, not the service contract.
        if (!$order instanceof Order) {
            return [];
        }

        // The same contact, cart and order summary as the status event, so both describe the order alike.
        $payload = $this->orderPayloadBuilder->build($order, $status);
        if (empty($payload['data']['order'])) {
            return [];
        }

        $payload['type'] = Notification::EVENT_ORDER_CREATED;
        // An order is created once: the key is the same on every attempt and every resend. "event-" keeps it
        // apart from the status keys (order-<id>-<status>), which a status coded "created" would match.
        $payload['idempotency_key'] = 'order-' . (int) $order->getEntityId() . '-event-created';
        $payload['data']['order'] += [
            'created_at' => $this->formatDate((string) $order->getCreatedAt()),
            'store_id' => (int) $order->getStoreId(),
            'website_id' => (int) $order->getStore()->getWebsiteId(),
            'coupon_code' => $this->getCouponCode($order),
            'totals' => $this->getTotals($order),
            'items' => $this->getItems($order),
            'customer' => $this->getCustomer($order),
            'billing_address' => $this->getAddress($order->getBillingAddress()),
            'shipping_address' => $this->getAddress($order->getShippingAddress()),
            'payment' => $this->getPayment($order),
            'shipping' => $this->getShipping($order),
        ];

        return $payload;
    }

    /**
     * @param string $createdAt sales_order.created_at, stored in UTC
     * @return string
     */
    private function formatDate(string $createdAt): string
    {
        if (empty($createdAt)) {
            return '';
        }

        return (new DateTimeImmutable($createdAt, new DateTimeZone('UTC')))->format(self::DATE_FORMAT);
    }

    /**
     * @param Order $order
     * @return string|null
     */
    private function getCouponCode(Order $order): ?string
    {
        $couponCode = (string) $order->getCouponCode();
        if (empty($couponCode)) {
            return null;
        }

        return $couponCode;
    }

    /**
     * @param Order $order
     * @return array<string, float>
     */
    private function getTotals(Order $order): array
    {
        // Magento stores the discount as a negative amount; Centraliza gets what the customer saved.
        return [
            'subtotal' => round((float) $order->getSubtotal(), 2),
            'discount' => round(abs((float) $order->getDiscountAmount()), 2),
            'tax' => round((float) $order->getTaxAmount(), 2),
            'shipping' => round((float) $order->getShippingAmount(), 2),
            'total' => round((float) $order->getGrandTotal(), 2),
        ];
    }

    /**
     * Lines the customer sees: a configurable product once, with its chosen options.
     *
     * @param Order $order
     * @return array<int, array<string, mixed>>
     */
    private function getItems(Order $order): array
    {
        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = [
                'sku' => (string) $item->getSku(),
                'name' => (string) $item->getName(),
                'quantity' => $this->quantityFormatter->format((float) $item->getQtyOrdered()),
                'price' => round((float) $item->getPrice(), 2),
                'row_total' => round((float) $item->getRowTotal(), 2),
                'discount' => round(abs((float) $item->getDiscountAmount()), 2),
                'options' => $this->getItemOptions($item),
            ];
        }

        return $items;
    }

    /**
     * Configurable choices and custom options, as label and value.
     *
     * @param OrderItem $item
     * @return array<int, array<string, string>>
     */
    private function getItemOptions(OrderItem $item): array
    {
        $productOptions = (array) $item->getProductOptions();
        $rawOptions = array_merge(
            (array) ($productOptions['attributes_info'] ?? []),
            (array) ($productOptions['options'] ?? [])
        );

        $options = [];
        foreach ($rawOptions as $rawOption) {
            $options[] = [
                'label' => (string) ($rawOption['label'] ?? ''),
                'value' => $this->getOptionValue($rawOption['print_value'] ?? $rawOption['value'] ?? ''),
            ];
        }

        return $options;
    }

    /**
     * An option value as text: a multiple choice comes as a list.
     *
     * @param mixed $value
     * @return string
     */
    private function getOptionValue(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }

        return (string) $value;
    }

    /**
     * @param Order $order
     * @return array<string, mixed>
     */
    private function getCustomer(Order $order): array
    {
        $customerId = (int) $order->getCustomerId();
        if (empty($customerId)) {
            return ['id' => null, 'is_guest' => true];
        }

        return ['id' => (string) $customerId, 'is_guest' => (bool) $order->getCustomerIsGuest()];
    }

    /**
     * @param OrderAddressInterface|null $address
     * @return array<string, mixed>|null
     */
    private function getAddress(?OrderAddressInterface $address): ?array
    {
        if (empty($address)) {
            return null;
        }

        return [
            'firstname' => (string) $address->getFirstname(),
            'lastname' => (string) $address->getLastname(),
            'street' => array_values((array) $address->getStreet()),
            'city' => (string) $address->getCity(),
            'region' => (string) $address->getRegionCode(),
            'postcode' => (string) $address->getPostcode(),
            'country_id' => (string) $address->getCountryId(),
            'telephone' => $this->formatTelephone($address),
        ];
    }

    /**
     * E.164 with the plus, or empty when the number is not usable.
     *
     * @param OrderAddressInterface $address
     * @return string
     */
    private function formatTelephone(OrderAddressInterface $address): string
    {
        $telephone = $this->phoneNumberFormatter->format($address->getTelephone(), $address->getCountryId());
        if (empty($telephone)) {
            return '';
        }

        return '+' . $telephone;
    }

    /**
     * @param Order $order
     * @return array<string, string>|null
     */
    private function getPayment(Order $order): ?array
    {
        $payment = $order->getPayment();
        if (empty($payment)) {
            return null;
        }

        // Only the method: card data, tokens and gateway fields never leave Magento.
        $method = (string) $payment->getMethod();
        $title = (string) ($payment->getAdditionalInformation()['method_title'] ?? '');
        if (empty($title)) {
            $title = $method;
        }

        return ['method' => $method, 'title' => $title];
    }

    /**
     * @param Order $order
     * @return array<string, mixed>
     */
    private function getShipping(Order $order): array
    {
        return [
            'method' => (string) $order->getShippingMethod(),
            'title' => (string) $order->getShippingDescription(),
            'amount' => round((float) $order->getShippingAmount(), 2),
        ];
    }
}
