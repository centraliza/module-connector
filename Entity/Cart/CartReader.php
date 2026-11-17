<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Cart;

use Centraliza\Connector\Entity\Customer\CustomerRecord;
use Centraliza\Connector\Entity\Names;
use Centraliza\Connector\Entity\ServiceRecord;
use Centraliza\Connector\Entity\StreamReaderInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteRepository\LoadHandler;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;

/**
 * The cart with everything GET /V1/carts/:id answers, plus its totals as
 * they were last calculated. Totals are not calculated again here: reading a
 * cart must not change it.
 */
class CartReader implements StreamReaderInterface
{
    private const FIRST = ['id', 'is_active', 'is_virtual', 'created_at', 'updated_at', 'converted_at', 'reserved_order_id'];
    private const PLACED = [
        'store_id', 'customer', 'customer_is_guest', 'items_count', 'items_qty', 'items', 'currency',
        'billing_address', 'extension_attributes',
    ];
    private const QUOTE_TOTALS = [
        'subtotal', 'base_subtotal', 'subtotal_with_discount', 'base_subtotal_with_discount', 'grand_total', 'base_grand_total',
    ];
    private const ADDRESS_TOTALS = [
        'shipping_amount', 'base_shipping_amount', 'shipping_incl_tax', 'base_shipping_incl_tax', 'discount_amount',
        'base_discount_amount', 'tax_amount', 'base_tax_amount', 'shipping_method', 'shipping_description',
    ];

    public function __construct(
        private readonly QuoteFactory $quoteFactory,
        private readonly QuoteResource $quoteResource,
        private readonly LoadHandler $loadHandler,
        private readonly ServiceRecord $serviceRecord,
        private readonly CustomerRecord $customerRecord,
        private readonly Names $names
    ) {
    }

    public function read(array $key): ?array
    {
        // Loaded here and not through the repository, which keeps every cart it
        // loaded and stamps it with the store view the process happens to run in.
        $quote = $this->quoteFactory->create();
        $this->quoteResource->loadByIdWithoutStore($quote, (int)$key['cart_id']);
        if (!$quote->getId()) {
            return null;
        }
        $storeId = (int)$quote->getStoreId();
        $this->loadHandler->load($quote);
        if ($quote->getItems() === null) {
            // Magento leaves a cart that is no longer active, such as one that became an order, without its items.
            $quote->setItems($quote->getAllVisibleItems());
        }
        $rest = $this->serviceRecord->toArray($quote, CartRepositoryInterface::class, 'get');
        $extension = (array)($rest['extension_attributes'] ?? []);
        $customer = (array)($rest['customer'] ?? []);

        $record = array_intersect_key($rest, array_flip(self::FIRST));
        $record = array_merge(array_intersect_key(array_flip(self::FIRST), $record), $record) + [
            'store' => $this->names->store($storeId),
            'customer' => isset($customer['id']) ? $this->customerRecord->arrange($customer, $storeId) : $customer,
            'customer_is_guest' => $rest['customer_is_guest'] ?? null,
            'items_count' => $rest['items_count'] ?? null,
            'items_qty' => $rest['items_qty'] ?? null,
            'items' => $rest['items'] ?? [],
            'currency' => $rest['currency'] ?? null,
            'coupon_code' => $quote->getCouponCode() ?: null,
            'totals' => $this->totals($quote),
            'billing_address' => $rest['billing_address'] ?? null,
            'shipping_assignments' => $extension['shipping_assignments'] ?? [],
        ];
        $record += array_diff_key($rest, array_flip(array_merge(self::FIRST, self::PLACED)));
        unset($extension['shipping_assignments']);
        if ($extension !== []) {
            $record['extension_attributes'] = $extension;
        }

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function totals(Quote $quote): array
    {
        $totals = [];
        foreach (self::QUOTE_TOTALS as $field) {
            $totals[$field] = $this->number($quote->getData($field));
        }
        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
        foreach (self::ADDRESS_TOTALS as $field) {
            $value = $address->getData($field);
            $totals[$field] = is_numeric($value) ? (float)$value : $value;
        }

        return $totals;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }
}
