<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api;

use Magento\Quote\Api\Data\CartInterface;

interface AbandonedCartPayloadBuilderInterface
{
    public const REASON_MISSING_CONTACT = 'missing_name_or_phone';
    public const REASON_UNSUPPORTED_CART = 'unsupported_cart';

    /**
     * Abandoned cart event Centraliza receives, or null when the cart cannot be sent; may create its masked id.
     *
     * @param CartInterface $quote
     * @param int $reminderNumber 0 for the first send of the cart, 1 and up for each resend after a change
     * @param string $reminderCreatedAt the resend row's created_at (UTC); unused for the first send
     * @return array|null
     */
    public function build(CartInterface $quote, int $reminderNumber = 0, string $reminderCreatedAt = ''): ?array;
}
