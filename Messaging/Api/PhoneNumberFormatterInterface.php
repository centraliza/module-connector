<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api;

/**
 * Formats a customer telephone the way Centraliza's messengers expect it: E.164 digits without
 * the leading plus. Each module decides what to do, and what to log, when the number is unusable.
 */
interface PhoneNumberFormatterInterface
{
    /**
     * E.164 digits without the leading plus, or null when the number is not a valid one.
     *
     * @param string|null $rawNumber
     * @param string|null $countryId the address country; Brazil when empty
     * @return string|null
     */
    public function format(?string $rawNumber, ?string $countryId): ?string;
}
