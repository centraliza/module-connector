<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\AbandonedCart\Payload;

use Centraliza\Connector\Messaging\Api\PhoneNumberFormatterInterface;
use Centraliza\Connector\Messaging\Model\Payload\CustomerWhatsapp;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Model\Quote;

class CustomerContactResolver
{
    /**
     * @param PhoneNumberFormatterInterface $phoneNumberFormatter
     * @param CustomerRepositoryInterface $customerRepository
     * @param AddressRepositoryInterface $addressRepository
     * @param CustomerWhatsapp $customerWhatsapp
     */
    public function __construct(
        private readonly PhoneNumberFormatterInterface $phoneNumberFormatter,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly CustomerWhatsapp $customerWhatsapp
    ) {
    }

    /**
     * Name and telephone the cart can be recovered through, or null when either is missing.
     *
     * @param Quote $quote
     * @return array{name: string, phone: string}|null
     */
    public function resolve(Quote $quote): ?array
    {
        // Source order: the customer's WhatsApp on the cart, then the cart's shipping address,
        // then its billing address, then the logged-in customer's default billing address. The
        // WhatsApp is the number the registration asks to be messaged on, so it wins; an empty
        // or unusable one passes to the telephones. The cart addresses are what the customer
        // typed for this purchase, so they win over the account, which is read only when no
        // cart source has a usable number.
        $cartSources = [
            $this->fromCustomerWhatsapp($quote),
            $this->fromQuoteAddress($quote->getShippingAddress()),
            $this->fromQuoteAddress($quote->getBillingAddress()),
        ];

        foreach ($cartSources as $source) {
            $phone = $this->phoneNumberFormatter->format($source['telephone'], $source['country_id']);
            if (!empty($phone)) {
                return $this->toContact($quote, $source, $phone);
            }
        }

        $accountSource = $this->getDefaultBillingAddress((int) $quote->getCustomerId());
        if (empty($accountSource)) {
            return null;
        }

        $phone = $this->phoneNumberFormatter->format($accountSource['telephone'], $accountSource['country_id']);
        if (empty($phone)) {
            return null;
        }

        return $this->toContact($quote, $accountSource, $phone);
    }

    /**
     * The name comes from the same source as the telephone when the cart itself carries none.
     *
     * @param Quote $quote
     * @param array{telephone: ?string, country_id: ?string, firstname: ?string, lastname: ?string} $source
     * @param string $phone
     * @return array{name: string, phone: string}|null
     */
    private function toContact(Quote $quote, array $source, string $phone): ?array
    {
        $firstname = trim((string) $quote->getCustomerFirstname());
        $lastname = trim((string) $quote->getCustomerLastname());

        if (empty($firstname) || empty($lastname)) {
            $firstname = trim((string) $source['firstname']);
            $lastname = trim((string) $source['lastname']);
        }

        $name = trim($firstname . ' ' . $lastname);
        if (empty($name)) {
            return null;
        }

        return ['name' => $name, 'phone' => $phone];
    }

    /**
     * @param Quote $quote
     * @return array{telephone: ?string, country_id: ?string, firstname: ?string, lastname: ?string}
     */
    private function fromCustomerWhatsapp(Quote $quote): array
    {
        // The WhatsApp keeps no country or name of its own: it is read in the billing address's
        // country, and a cart without the customer's name takes the billing address's.
        $billingAddress = $quote->getBillingAddress();

        return [
            'telephone' => $this->customerWhatsapp->getQuoteWhatsapp($quote),
            'country_id' => $billingAddress?->getCountryId(),
            'firstname' => $billingAddress?->getFirstname(),
            'lastname' => $billingAddress?->getLastname(),
        ];
    }

    /**
     * @param AddressInterface|null $address
     * @return array{telephone: ?string, country_id: ?string, firstname: ?string, lastname: ?string}
     */
    private function fromQuoteAddress(?AddressInterface $address): array
    {
        return [
            'telephone' => $address?->getTelephone(),
            'country_id' => $address?->getCountryId(),
            'firstname' => $address?->getFirstname(),
            'lastname' => $address?->getLastname(),
        ];
    }

    /**
     * @param int $customerId
     * @return array{telephone: ?string, country_id: ?string, firstname: ?string, lastname: ?string}|null
     */
    private function getDefaultBillingAddress(int $customerId): ?array
    {
        if (empty($customerId)) {
            return null;
        }

        try {
            $addressId = $this->customerRepository->getById($customerId)->getDefaultBilling();
            if (empty($addressId)) {
                return null;
            }

            $address = $this->addressRepository->getById((int) $addressId);
        } catch (LocalizedException) {
            // A customer or address deleted after the cart was created is a missing source,
            // not an error: the cart simply has no telephone from the account.
            return null;
        }

        return [
            'telephone' => $address->getTelephone(),
            'country_id' => $address->getCountryId(),
            'firstname' => $address->getFirstname(),
            'lastname' => $address->getLastname(),
        ];
    }
}
