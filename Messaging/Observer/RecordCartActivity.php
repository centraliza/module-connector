<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Observer;

use Centraliza\Connector\Messaging\Model\LegacyModules;
use Centraliza\Connector\Messaging\Model\ResourceModel\AbandonedCart\CartActivity;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class RecordCartActivity implements ObserverInterface
{
    /**
     * @param CheckoutSession $checkoutSession
     * @param CustomerSession $customerSession
     * @param CartActivity $cartActivity
     * @param LoggerInterface $logger
     * @param LegacyModules $legacyModules
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly CartActivity $cartActivity,
        private readonly LoggerInterface $logger,
        private readonly LegacyModules $legacyModules
    ) {
    }

    /**
     * A customer on the cart or the checkout page has not abandoned the cart.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        // Centraliza_Sales does the same while it is enabled; doing it twice would replace or move the cart twice.
        if ($this->legacyModules->areActive()) {
            return;
        }

        $quoteId = 0;

        try {
            // Read from the session only: loading the quote here would create one for a visitor without a cart.
            $quoteId = (int) $this->checkoutSession->getQuoteId();
            // Customer carts only, the ones the abandoned cart sends; a remembered customer counts.
            if (empty($quoteId) || empty($this->customerSession->getCustomerId())) {
                return;
            }

            $this->cartActivity->touch($quoteId);
        } catch (Throwable $exception) {
            // The page must open even when the activity cannot be written.
            $this->logger->warning('Centraliza cart activity could not be recorded', [
                'cart_id' => $quoteId,
                'exception_class' => $exception::class,
            ]);
        }
    }
}
