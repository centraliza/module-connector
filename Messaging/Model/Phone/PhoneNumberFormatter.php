<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Phone;

use Centraliza\Connector\Messaging\Api\PhoneNumberFormatterInterface;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

class PhoneNumberFormatter implements PhoneNumberFormatterInterface
{
    /**
     * The stores this integration serves are Brazilian: an address without a country is read as BR,
     * so a number typed without the country code still formats.
     */
    private const DEFAULT_REGION = 'BR';

    private readonly PhoneNumberUtil $phoneNumberUtil;

    /**
     * PhoneNumberUtil has a private constructor, so it cannot be injected; core does the same
     * in PaymentServicesPaypal\Model\Api\PhoneNumberService.
     */
    public function __construct()
    {
        $this->phoneNumberUtil = PhoneNumberUtil::getInstance();
    }

    /**
     * @param string|null $rawNumber
     * @param string|null $countryId
     * @return string|null
     */
    public function format(?string $rawNumber, ?string $countryId): ?string
    {
        $rawNumber = trim((string) $rawNumber);
        if (empty($rawNumber)) {
            return null;
        }

        $region = strtoupper(trim((string) $countryId));
        if (empty($region)) {
            $region = self::DEFAULT_REGION;
        }

        try {
            $parsed = $this->phoneNumberUtil->parse($rawNumber, $region);
        } catch (NumberParseException) {
            return null;
        }

        // A number can parse and still not exist for its region: a local number missing its
        // area code formats into something plausible that never reaches the customer.
        if (!$this->phoneNumberUtil->isValidNumber($parsed)) {
            return null;
        }

        // Centraliza expects the recipient without the E.164 leading "+".
        return ltrim($this->phoneNumberUtil->format($parsed, PhoneNumberFormat::E164), '+');
    }
}
