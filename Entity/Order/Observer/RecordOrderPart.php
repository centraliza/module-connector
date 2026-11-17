<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Order\Observer;

use Centraliza\Connector\Entity\Order\OrderRecorder;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Invoices, shipments, credit memos, tracking numbers, comments and addresses
 * can be saved without the order row being saved; each reports its order.
 */
class RecordOrderPart implements ObserverInterface
{
    public function __construct(
        private readonly OrderRecorder $recorder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $part = $observer->getEvent()->getData('data_object');
            if ($part instanceof DataObject) {
                // A tracking number has both: its parent is the shipment.
                $this->recorder->recordUpdatedById((int)($part->getData('order_id') ?: $part->getData('parent_id')));
            }
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not record a change to part of an order.', ['exception' => $e]);
        }
    }
}
