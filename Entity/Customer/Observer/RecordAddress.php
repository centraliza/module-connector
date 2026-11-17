<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Customer\Observer;

use Centraliza\Connector\Entity\Customer\CustomerRecorder;
use Magento\Customer\Model\Address;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * An address is not a record of its own: saving or deleting one reports its customer.
 */
class RecordAddress implements ObserverInterface
{
    public function __construct(
        private readonly CustomerRecorder $recorder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $address = $observer->getEvent()->getData('customer_address');
            if (!$address instanceof Address) {
                return;
            }
            $customerId = (int)($address->getParentId() ?: $address->getCustomerId());
            if ($customerId === 0) {
                return;
            }
            if (str_ends_with((string)$observer->getEvent()->getName(), '_before')) {
                $this->recorder->remember($customerId);
            } else {
                $this->recorder->compare($customerId);
            }
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not record a customer address change.', ['exception' => $e]);
        }
    }
}
