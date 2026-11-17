<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Cart\Observer;

use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Entity\Cart\CartDiff;
use Centraliza\Connector\Entity\Cart\CartRecorder;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A cart is saved on almost every storefront request; only a save that
 * changes it is reported.
 */
class RecordCart implements ObserverInterface
{
    public function __construct(
        private readonly CartRecorder $recorder,
        private readonly CartDiff $diff,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $quote = $observer->getEvent()->getData('quote');
            if (!$quote instanceof Quote) {
                return;
            }
            $verb = $observer->getEvent()->getName() === 'sales_quote_delete_after'
                ? EventVerb::Deleted
                : $this->verbOfSave($quote);
            if ($verb !== null) {
                $this->recorder->record($verb, (int)$quote->getId(), (int)$quote->getStoreId(), (int)$quote->getCustomerId());
            }
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not record a cart change.', ['exception' => $e]);
        }
    }

    private function verbOfSave(Quote $quote): ?EventVerb
    {
        if ($this->diff->isNew($quote)) {
            return EventVerb::Created;
        }
        // A guest cart that was not being reported becomes known when it gets its customer.
        if ((int)$quote->getOrigData('customer_id') === 0
            && (int)$quote->getCustomerId() > 0
            && !$this->recorder->isReported((int)$quote->getStoreId(), 0)
        ) {
            return EventVerb::Created;
        }

        return $this->diff->changed($quote) ? EventVerb::Updated : null;
    }
}
