<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Customer;

use Centraliza\Connector\Core\Port\SettingsInterface;
use Centraliza\Connector\Entity\ServiceRecord;
use Centraliza\Connector\Entity\StreamReaderInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\CustomerRegistry;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * The customer with everything GET /V1/customers/:id answers, addresses included.
 */
class CustomerReader implements StreamReaderInterface
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
        private readonly CustomerRegistry $registry,
        private readonly ServiceRecord $serviceRecord,
        private readonly CustomerRecord $customerRecord,
        private readonly SettingsInterface $settings
    ) {
    }

    public function read(array $key): ?array
    {
        $customerId = (int)$key['customer_id'];
        try {
            // The registry keeps what it loaded for the whole life of a queue consumer.
            $this->registry->remove($customerId);
            $customer = $this->customers->getById($customerId);
        } catch (NoSuchEntityException) {
            return null;
        }

        return $this->customerRecord->arrange(
            $this->serviceRecord->toArray($customer, CustomerRepositoryInterface::class, 'getById'),
            (int)$this->settings->reportingStoreId()
        );
    }
}
