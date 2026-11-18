<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\AbandonedCart\Payload;

use Centraliza\Connector\Messaging\Api\AbandonedCartPayloadBuilderInterface;
use Centraliza\Connector\Messaging\Api\CartRedirectConfigInterface;
use Centraliza\Connector\Messaging\Model\Payload\QuantityFormatter;
use Centraliza\Connector\Messaging\Model\ResourceModel\CartRedirect\CartLink;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Math\Random;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Psr\Log\LoggerInterface;

class PayloadBuilder implements AbandonedCartPayloadBuilderInterface
{
    /**
     * Centraliza event contract: the event type of an abandoned cart on the events route.
     */
    private const EVENT_TYPE = 'cart.abandoned';

    /**
     * @param CustomerContactResolver $customerContactResolver
     * @param CartLink $cartLink
     * @param Random $random
     * @param CartRedirectConfigInterface $cartRedirectConfig
     * @param QuantityFormatter $quantityFormatter
     * @param LoggerInterface $debugLogger written only while the Base Debug Log is on
     */
    public function __construct(
        private readonly CustomerContactResolver $customerContactResolver,
        private readonly CartLink $cartLink,
        private readonly Random $random,
        private readonly CartRedirectConfigInterface $cartRedirectConfig,
        private readonly QuantityFormatter $quantityFormatter,
        private readonly LoggerInterface $debugLogger
    ) {
    }

    /**
     * @param CartInterface $quote
     * @param int $reminderNumber
     * @param string $reminderCreatedAt
     * @return array|null
     */
    public function build(CartInterface $quote, int $reminderNumber = 0, string $reminderCreatedAt = ''): ?array
    {
        if (!$quote instanceof Quote) {
            return $this->discard((int) $quote->getId(), self::REASON_UNSUPPORTED_CART);
        }

        $cartId = (int) $quote->getId();

        $contact = $this->customerContactResolver->resolve($quote);
        if (empty($contact)) {
            return $this->discard($cartId, self::REASON_MISSING_CONTACT);
        }

        $items = $this->buildItems($quote);

        // The subtotal is read as stored: collectTotals() or any save of the quote would move
        // its updated_at, which is what decides abandonment, and the module itself would pull
        // the cart out of the cutoff.
        return [
            'type' => self::EVENT_TYPE,
            'idempotency_key' => $this->getIdempotencyKey($cartId, $reminderNumber, $reminderCreatedAt),
            'contact' => [
                'name' => $contact['name'],
                'phone' => '+' . $contact['phone'],
                'email' => (string) $quote->getCustomerEmail(),
                // Fixed true while the consent source is being decided, so test stores can follow the flow. It
                // reports consent the customer may not have given: not for a production store (LGPD).
                'marketing_opt_in' => true,
            ],
            'data' => [
                'cart' => [
                    'id' => (string) $cartId,
                    'masked_id' => $this->getMaskedId($quote),
                    'total' => round((float) $quote->getSubtotal(), 2),
                    'currency' => (string) $quote->getQuoteCurrencyCode(),
                    // Centraliza contract: the cart volume, the quote's items_qty as Magento's quantity
                    // collector stores it (the sum of the visible lines), the figure the minicart shows.
                    'items_count' => $this->quantityFormatter->format((float) $quote->getItemsQty()),
                    'items' => $items,
                ],
            ],
        ];
    }

    /**
     * cart-<id> for the first send, cart-<id>-<YmdHis> for a resend.
     *
     * @param int $cartId
     * @param int $reminderNumber
     * @param string $reminderCreatedAt
     * @return string
     */
    private function getIdempotencyKey(int $cartId, int $reminderNumber, string $reminderCreatedAt): string
    {
        if (empty($reminderNumber)) {
            return 'cart-' . $cartId;
        }

        // The resend row's created_at (UTC, as stored), digits only: written once when the resend is
        // created, so every retry of that resend carries the same key and Centraliza can tell it apart.
        return 'cart-' . $cartId . '-' . preg_replace('/\D/', '', $reminderCreatedAt);
    }

    /**
     * Masked id Centraliza builds the cart redirect link with, created when the cart has none.
     *
     * @param Quote $quote
     * @return string
     */
    private function getMaskedId(Quote $quote): string
    {
        // The id opens the customer's cart through the link, so it only leaves Magento when the link works.
        if (!$this->cartRedirectConfig->isEnabled((int) $quote->getStore()->getWebsiteId())) {
            return '';
        }

        $cartId = (int) $quote->getId();
        $linkId = $this->cartLink->getMaskedId($cartId);
        if (!empty($linkId)) {
            return $linkId;
        }

        // Never the cart's quote_id_mask row: Hyvä and the core GraphQL keep it in the browser as the cart id.
        $linkId = $this->random->getUniqueHash();
        $this->cartLink->add($cartId, $linkId);

        return $linkId;
    }

    /**
     * @param Quote $quote
     * @return array
     */
    private function buildItems(Quote $quote): array
    {
        $items = [];

        // Visible items only: a configurable parent carries the chosen child's SKU, and adding
        // the hidden child as well would send the same product twice with doubled quantity.
        /** @var Item $item */
        foreach ($quote->getAllVisibleItems() as $item) {
            $items[] = [
                'sku' => (string) $item->getSku(),
                'name' => $this->getItemName($item),
                'quantity' => $this->quantityFormatter->format((float) $item->getQty()),
                'price' => round((float) $item->getPrice(), 2),
            ];
        }

        return $items;
    }

    /**
     * @param Item $item
     * @return string
     */
    private function getItemName(Item $item): string
    {
        if ($item->getProductType() !== Configurable::TYPE_CODE) {
            return (string) $item->getName();
        }

        // A configurable parent carries the product's generic name; the chosen option is only in
        // its hidden child ("... - Número 0"). With two options of the same product in the cart,
        // the parent name would repeat and the customer could not tell the lines apart. Bundles
        // are left out: each of their children is one part of the kit, not the chosen product.
        $children = $item->getChildren();
        if (empty($children)) {
            return (string) $item->getName();
        }

        $optionName = (string) reset($children)->getName();
        if (empty($optionName)) {
            return (string) $item->getName();
        }

        return $optionName;
    }

    /**
     * @param int $cartId
     * @param string $reason
     * @return null
     */
    private function discard(int $cartId, string $reason): null
    {
        // Only the cart id and the reason: the customer's name and telephone never reach the log.
        $this->debugLogger->info(
            'Centraliza abandoned cart skipped',
            ['cart_id' => $cartId, 'reason' => $reason]
        );

        return null;
    }
}
