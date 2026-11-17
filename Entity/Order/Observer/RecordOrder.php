<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Order\Observer;

use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Core\Service\RecordDiff;
use Centraliza\Connector\Entity\Order\OrderRecorder;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Throwable;

class RecordOrder implements ObserverInterface
{
    private const IGNORED = ['updated_at'];

    public function __construct(
        private readonly OrderRecorder $recorder,
        private readonly RecordDiff $diff,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $order = $observer->getEvent()->getData('order');
            if (!$order instanceof Order) {
                return;
            }
            $original = (array)$order->getOrigData();
            // An order saved for the first time was never loaded, so it has nothing original.
            if (($original['entity_id'] ?? null) === null) {
                $verb = EventVerb::Created;
            } elseif ($this->diff->changedFields((array)$order->getData(), $original, self::IGNORED) !== []) {
                $verb = EventVerb::Updated;
            } else {
                return;
            }
            $this->recorder->record($verb, (int)$order->getId(), (string)$order->getIncrementId(), (int)$order->getStoreId());
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not record an order save.', ['exception' => $e]);
        }
    }
}
