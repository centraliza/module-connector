<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Observer;

use Centraliza\Connector\Messaging\Model\LegacyModules;
use Centraliza\Connector\Messaging\Model\CartRedirect\CartOrigin;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Api\Data\OrderInterface;

class CopyCartOriginToOrder implements ObserverInterface
{
    /**
     * @param CartOrigin $cartOrigin
     * @param LegacyModules $legacyModules
     */
    public function __construct(
        private readonly CartOrigin $cartOrigin,
        private readonly LegacyModules $legacyModules
    ) {
    }

    /**
     * Copy the cart's Centraliza link mark to the order the core is about to place.
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

        $quote = $observer->getEvent()->getData('quote');
        $order = $observer->getEvent()->getData('order');
        if (!$quote instanceof CartInterface || !$order instanceof OrderInterface) {
            return;
        }

        // QuoteManagement::submitQuote() rebuilds the converted order through the OrderInterface getters, which
        // drops the sales_convert_quote fieldset copy of a column outside the service contract.
        $this->cartOrigin->copyToOrder($quote, $order);
    }
}
