<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Customer\Observer;

use Centraliza\Connector\Entity\Customer\CustomerRecorder;
use Magento\Customer\Model\Customer;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use WeakMap;

/**
 * Listens before and after each customer save and delete.
 */
class RecordCustomer implements ObserverInterface
{
    /** @var WeakMap<Customer, true> customers that had no id before their save */
    private WeakMap $new;

    public function __construct(
        private readonly CustomerRecorder $recorder,
        private readonly LoggerInterface $logger
    ) {
        $this->new = new WeakMap();
    }

    public function execute(Observer $observer): void
    {
        try {
            $customer = $observer->getEvent()->getData('customer');
            if (!$customer instanceof Customer) {
                return;
            }
            $customerId = (int)$customer->getId();

            switch ($observer->getEvent()->getName()) {
                case 'customer_save_before':
                    if ($customerId === 0) {
                        $this->new[$customer] = true;
                    }
                    $this->recorder->remember($customerId);
                    break;
                case 'customer_save_after':
                    if (isset($this->new[$customer])) {
                        unset($this->new[$customer]);
                        $this->recorder->created($customerId);
                    } else {
                        $this->recorder->compare($customerId);
                    }
                    break;
                case 'customer_delete_before':
                    $this->recorder->remember($customerId);
                    break;
                case 'customer_delete_after':
                    $this->recorder->deleted($customerId);
                    break;
            }
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not record a customer change.', ['exception' => $e]);
        }
    }
}
