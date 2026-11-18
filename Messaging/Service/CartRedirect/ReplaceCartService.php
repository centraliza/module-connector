<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Service\CartRedirect;

use Centraliza\Connector\Messaging\Api\ReplaceCartServiceInterface;
use Centraliza\Connector\Messaging\Model\CartRedirect\CartOrigin;
use Centraliza\Connector\Messaging\Model\CartRedirect\QuoteResolver;
use Centraliza\Connector\Messaging\Model\ResourceModel\CartRedirect\CartLink;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\CartLockedException;
use Magento\Quote\Model\CartMutexInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class ReplaceCartService implements ReplaceCartServiceInterface
{
    /**
     * Two links opened at once by the same owner (a double tap, a browser retry, two devices of one
     * customer) would both deactivate the same previous cart and leave two active carts; the lock
     * makes the second request wait for the first. Ten seconds was not measured on this flow: it
     * only bounds the wait behind a stuck request.
     */
    private const LOCK_TIMEOUT_SECONDS = 10;
    private const LOCK_PREFIX = 'centraliza_sales_cart_redirect_';
    private const SESSION_GUEST_LINK_QUOTE_ID = 'centraliza_cart_redirect_quote_id';

    /**
     * @param QuoteResolver $quoteResolver
     * @param CartMutexInterface $cartMutex
     * @param CheckoutSession $checkoutSession
     * @param CustomerSession $customerSession
     * @param CartRepositoryInterface $cartRepository
     * @param QuoteFactory $quoteFactory
     * @param QuoteResource $quoteResource
     * @param StoreManagerInterface $storeManager
     * @param LockManagerInterface $lockManager
     * @param AddressInterfaceFactory $addressFactory
     * @param CustomerRepositoryInterface $customerRepository
     * @param LoggerInterface $logger
     * @param CartOrigin $cartOrigin
     * @param CartLink $cartLink
     */
    public function __construct(
        private readonly QuoteResolver $quoteResolver,
        private readonly CartMutexInterface $cartMutex,
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly QuoteFactory $quoteFactory,
        private readonly QuoteResource $quoteResource,
        private readonly StoreManagerInterface $storeManager,
        private readonly LockManagerInterface $lockManager,
        private readonly AddressInterfaceFactory $addressFactory,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly LoggerInterface $logger,
        private readonly CartOrigin $cartOrigin,
        private readonly CartLink $cartLink
    ) {
    }

    /**
     * @param string $maskedId
     * @return void
     * @throws LocalizedException
     */
    public function replace(string $maskedId): void
    {
        /** @var Store $store */
        $store = $this->storeManager->getStore();
        $quoteId = $this->getQuoteId($maskedId, (int) $store->getId());

        $this->assignWithLocks(
            $quoteId,
            $store,
            fn () => (int) $this->checkoutSession->getQuote()->getId(),
            $maskedId
        );
    }

    /**
     * @param string $maskedId
     * @param int $storeId
     * @return int
     * @throws LocalizedException
     */
    private function getQuoteId(string $maskedId, int $storeId): int
    {
        try {
            return $this->quoteResolver->getQuoteId($maskedId);
        } catch (LocalizedException $exception) {
            $this->logRejection($exception, $storeId);
            throw $exception;
        }
    }

    /**
     * @param int $quoteId
     * @param Store $store
     * @param int $previousQuoteId
     * @param string $maskedId masked id of the link being opened, empty when no link is being opened
     * @return void
     * @throws LocalizedException
     */
    private function assignQuote(int $quoteId, Store $store, int $previousQuoteId, string $maskedId): void
    {
        $storeId = (int) $store->getId();

        // Everything is validated in memory before the save: a rejection never saves the
        // Centraliza quote nor points the session to it.
        try {
            $quote = $this->quoteResolver->get($quoteId, (int) $store->getWebsiteId());

            $this->checkOwner($quote);

            // A cart opened by a Centraliza link is the Centraliza cart from then on, whatever the customer
            // changes in it: its order is reported to Centraliza.
            $this->cartOrigin->markQuote($quote, $maskedId);

            // Bound once: assignCustomer() without addresses imports the customer's default ones, which would
            // replace the addresses the customer already typed in the checkout when the link is opened again.
            if (!empty($this->getCustomerId()) && empty($quote->getCustomerId())) {
                $this->clearGuestAddresses($quote);
                // Read by id, as the checkout session does: for a remembered customer the session's
                // customer model may have been created empty before the persistent cart set the id.
                $quote->assignCustomer($this->customerRepository->getById($this->getCustomerId()));
                $quote->setCustomerIsGuest(false);
            }

            $quote->setStore($store);
            $this->collectTotals($quote);
        } catch (LocalizedException $exception) {
            $this->logRejection($exception, $storeId);
            throw $exception;
        }

        try {
            $this->saveQuote($quote, $previousQuoteId, $maskedId);
        } catch (LocalizedException $exception) {
            $this->logger->error('Centraliza cart redirect failed', [
                'exception_class' => $exception::class,
                'store_id' => $storeId,
            ]);
            throw $exception;
        }

        $this->checkoutSession->replaceQuote($quote);

        // A visitor who signs in afterwards keeps only this cart (replaceOnLogin).
        if (empty($this->getCustomerId())) {
            $this->checkoutSession->setData(self::SESSION_GUEST_LINK_QUOTE_ID, (int) $quote->getId());
        }
    }

    /**
     * Move the link id of a customer's cart out of quote_id_mask.
     *
     * @param Quote $quote
     * @param string $linkId
     * @return void
     */
    private function keepCustomerLink(Quote $quote, string $linkId): void
    {
        // A guest's cart keeps it in quote_id_mask: Centraliza edits that cart on the guest cart API.
        if (empty($this->getCustomerId()) || empty($linkId)) {
            return;
        }

        $this->cartLink->keep((int) $quote->getId(), $linkId);
    }

    /**
     * @return void
     * @throws LocalizedException
     */
    public function replaceOnLogin(): void
    {
        // Read once: the mark is cleared by this login whether or not it replaces a cart.
        $linkQuoteId = (int) $this->checkoutSession->getData(self::SESSION_GUEST_LINK_QUOTE_ID, true);
        if (empty($linkQuoteId) || $linkQuoteId !== (int) $this->checkoutSession->getQuoteId()) {
            return;
        }

        // Read from the database: every core login reactivates the session cart in memory before this runs
        // (Persistent's QuoteManager::expire(), even with the feature off), so a link's cart ordered or
        // taken by another customer meanwhile would look open.
        $linkQuote = $this->quoteFactory->create();
        $this->quoteResource->load($linkQuote, $linkQuoteId);
        if (empty($linkQuote->getIsActive())) {
            return;
        }

        $this->checkOwner($linkQuote);

        // Before the binding: if it fails, the core login takes the cart over and deletes its quote_id_mask row.
        $this->keepCustomerLink($linkQuote, $this->cartOrigin->getQuoteMaskedId($linkQuote));

        /** @var Store $store */
        $store = $this->storeManager->getStore();

        // The core login would merge the link's cart into the customer's active cart. It is bound here
        // exactly as when a logged-in customer opens the link: validated again, addresses replaced by
        // the customer's, the previous cart deactivated. The core then finds the link's cart as the
        // customer's cart and merges nothing.
        $this->assignWithLocks($linkQuoteId, $store, fn () => $this->getActiveCustomerQuoteId($store), '');
    }

    /**
     * Assign the quote under the cart owner's lock and the quote's own lock.
     *
     * @param int $quoteId
     * @param Store $store
     * @param callable $getPreviousQuoteId Read once both locks are held.
     * @param string $maskedId masked id of the link being opened, empty when no link is being opened
     * @return void
     * @throws LocalizedException
     */
    private function assignWithLocks(int $quoteId, Store $store, callable $getPreviousQuoteId, string $maskedId): void
    {
        $lockName = $this->getLockName();
        if (!$this->lockManager->lock($lockName, self::LOCK_TIMEOUT_SECONDS)) {
            $exception = new LocalizedException(__('cart_busy'));
            $this->logRejection($exception, (int) $store->getId());
            throw $exception;
        }

        try {
            // The Centraliza quote is shared by whoever holds the link, so it is read and saved under
            // the lock core takes to place an order: a quote being ordered, or opened at the same
            // time by another visitor, is never saved over.
            $this->cartMutex->execute(
                $quoteId,
                fn () => $this->assignQuote($quoteId, $store, $getPreviousQuoteId(), $maskedId)
            );
        } catch (CartLockedException) {
            $exception = new LocalizedException(__('cart_busy'));
            $this->logRejection($exception, (int) $store->getId());
            throw $exception;
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    /**
     * Active cart of the session customer, read from the database, or 0.
     *
     * @param Store $store
     * @return int
     */
    private function getActiveCustomerQuoteId(Store $store): int
    {
        // A fresh instance, never the repository's cached one: the core login reads that cache next.
        $quote = $this->quoteFactory->create();
        $quote->setStoreId((int) $store->getId());
        $this->quoteResource->loadByCustomerId($quote, $this->getCustomerId());

        return (int) $quote->getId();
    }

    /**
     * Empty the guest quote's addresses and shipping method before it is bound to the customer.
     *
     * @param Quote $quote
     * @return void
     */
    private function clearGuestAddresses(Quote $quote): void
    {
        // assignCustomer() keeps an address the customer has no default for, so a guest-built
        // address would reach the customer's checkout. The shipping method goes with it: the save
        // applies it again from the shipping assignment and fails on an address without a country.
        $emptyAddress = $this->addressFactory->create();
        $quote->getBillingAddress()->importCustomerAddressData($emptyAddress);
        $quote->getShippingAddress()->importCustomerAddressData($emptyAddress)->setShippingMethod(null);

        foreach ((array) $quote->getExtensionAttributes()?->getShippingAssignments() as $shippingAssignment) {
            $shippingAssignment->getShipping()->setMethod(null);
        }
    }

    /**
     * Save the Centraliza quote and deactivate the customer's previous cart in one transaction.
     *
     * @param Quote $quote
     * @param int $previousQuoteId
     * @param string $linkId
     * @return void
     * @throws Throwable
     */
    private function saveQuote(Quote $quote, int $previousQuoteId, string $linkId): void
    {
        $connection = $this->quoteResource->getConnection();
        $connection->beginTransaction();

        try {
            $this->cartRepository->save($quote);

            // The customer's previous cart is replaced, never merged: the link opens exactly the
            // cart the seller built, and the customer never keeps two active carts. A link to the
            // cart the customer already has (a reopened link) has nothing to replace.
            $hasPreviousCart = !empty($previousQuoteId) && $previousQuoteId !== (int) $quote->getId();
            if (!empty($this->getCustomerId()) && $hasPreviousCart) {
                $this->deactivateQuote($previousQuoteId);
            }

            $this->keepCustomerLink($quote, $linkId);

            $connection->commit();
        } catch (Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    /**
     * A guest quote opens for whoever holds the link; a customer's quote only for that customer.
     *
     * @param Quote $quote
     * @return void
     * @throws LocalizedException
     */
    private function checkOwner(Quote $quote): void
    {
        $customerId = (int) $quote->getCustomerId();
        if (empty($customerId)) {
            return;
        }

        // Only an earlier open of this link by a customer binds the quote to that customer; from
        // then on, only that customer reopens it.
        if ($customerId === $this->getCustomerId()) {
            return;
        }

        throw new LocalizedException(__(ReplaceCartServiceInterface::REASON_UNAUTHORIZED_QUOTE));
    }

    /**
     * @param Quote $quote
     * @return void
     * @throws LocalizedException
     */
    private function collectTotals(Quote $quote): void
    {
        $quote->getShippingAddress()->setCollectShippingRates(true);
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();

        foreach ($quote->getAllItems() as $item) {
            if ($item->getHasError()) {
                throw new LocalizedException(__('not_salable: %1: %2', $item->getSku(), $item->getMessage()));
            }
        }

        if ($quote->getHasError()) {
            throw new LocalizedException(__('not_salable'));
        }
    }

    /**
     * @param int $quoteId
     * @return void
     */
    private function deactivateQuote(int $quoteId): void
    {
        // The session instance carries items, addresses and totals changed in memory, so the previous
        // cart is loaded again from the database and saved with only its active flag changed.
        $quote = $this->quoteFactory->create();
        $this->quoteResource->load($quote, $quoteId);
        if (empty($quote->getId())) {
            return;
        }

        $quote->setIsActive(false);
        $this->quoteResource->save($quote);
    }

    /**
     * Customer who owns the session cart, or 0 for a guest.
     *
     * @return int
     */
    private function getCustomerId(): int
    {
        // A customer remembered by the persistent cart after the login expired is not logged in,
        // but the session still holds that customer's cart: the link replaces it exactly as it
        // does for a logged-in customer.
        return (int) $this->customerSession->getCustomerId();
    }

    /**
     * One lock per cart owner: the session customer, otherwise the session.
     *
     * @return string
     */
    private function getLockName(): string
    {
        $customerId = $this->getCustomerId();
        if (!empty($customerId)) {
            return self::LOCK_PREFIX . 'customer_' . $customerId;
        }

        // Hashed so the session id never shows up in the database lock list.
        return self::LOCK_PREFIX . 'session_' . sha1((string) $this->checkoutSession->getSessionId());
    }

    /**
     * @param LocalizedException $exception
     * @param int $storeId
     * @return void
     */
    private function logRejection(LocalizedException $exception, int $storeId): void
    {
        $this->logger->warning('Centraliza cart redirect rejected', [
            'reason' => $exception->getLogMessage(),
            'store_id' => $storeId,
        ]);
    }
}
