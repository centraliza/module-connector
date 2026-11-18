<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Observer;

use Centraliza\Connector\Messaging\Model\LegacyModules;
use Centraliza\Connector\Messaging\Model\CartRedirect\CartOrigin;
use Centraliza\Connector\Messaging\Model\ResourceModel\CartRedirect\CartLink;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;
use Throwable;

class CarryCartLinkOnMerge implements ObserverInterface
{
    /**
     * @param CartOrigin $cartOrigin
     * @param CartLink $cartLink
     * @param LoggerInterface $logger
     * @param LegacyModules $legacyModules
     */
    public function __construct(
        private readonly CartOrigin $cartOrigin,
        private readonly CartLink $cartLink,
        private readonly LoggerInterface $logger,
        private readonly LegacyModules $legacyModules
    ) {
    }

    /**
     * Give the cart Magento merged a Centraliza cart into its mark and link ids; the caller saves the cart.
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

        $target = $observer->getEvent()->getData('quote');
        $source = $observer->getEvent()->getData('source');
        if (!$target instanceof Quote || !$source instanceof Quote) {
            return;
        }

        // A cart that arrives empty brings nothing from a link: the cart it merges into stays the store's own.
        if (empty($source->getAllVisibleItems())) {
            return;
        }

        // The core deletes the merged cart next, so its mark and link ids go to the cart that holds its items.
        $maskedId = $this->cartOrigin->getQuoteMaskedId($source);
        $this->cartOrigin->markQuote($target, $maskedId);

        $targetId = (int) $target->getId();
        if (empty($targetId)) {
            return;
        }

        // The merge must go on whatever fails here; the link then stops opening, as before the merge.
        try {
            $this->cartLink->moveToQuote((int) $source->getId(), $targetId);
            if (!empty($maskedId)) {
                $this->cartLink->keep($targetId, $maskedId);
            }
        } catch (Throwable $exception) {
            $this->logger->error('Centraliza cart link could not follow a merged cart', [
                'exception_class' => $exception::class,
                'cart_id' => $targetId,
            ]);
        }
    }
}
