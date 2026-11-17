<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Cart\Observer;

use Centraliza\Connector\Entity\Cart\CartDiff;
use Centraliza\Connector\Entity\Cart\CartRecorder;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Model\AbstractModel;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Items and addresses are saved along with the cart, changed or not; one
 * that did change reports its cart.
 */
class RecordCartPart implements ObserverInterface
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
            $part = $observer->getEvent()->getData('data_object');
            if (!$part instanceof AbstractModel) {
                return;
            }
            $deleted = str_ends_with((string)$observer->getEvent()->getName(), '_delete_after');
            if ($deleted || $this->diff->changed($part)) {
                $this->recorder->recordUpdatedById((int)$part->getData('quote_id'));
            }
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not record a change to part of a cart.', ['exception' => $e]);
        }
    }
}
