<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api;

use Magento\Framework\Exception\LocalizedException;

interface ReplaceCartServiceInterface
{
    /**
     * The quote belongs to a customer other than the session's customer.
     */
    public const REASON_UNAUTHORIZED_QUOTE = 'unauthorized_quote';

    /**
     * Make the Centraliza quote behind the masked id the session cart, or change nothing.
     *
     * @param string $maskedId
     * @return void
     * @throws LocalizedException
     */
    public function replace(string $maskedId): void;

    /**
     * On login, replace the customer's cart with the cart a guest link opened in this session, or change nothing.
     *
     * @return void
     * @throws LocalizedException
     */
    public function replaceOnLogin(): void;
}
