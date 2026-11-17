<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\CustomerGroup\Observer;

use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Core\Port\ChangeRecorderInterface;
use Centraliza\Connector\Core\Service\RecordDiff;
use Magento\Customer\Model\Group;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Customer groups are global: they are reported whenever any store view reports.
 */
class RecordGroup implements ObserverInterface
{
    public function __construct(
        private readonly ChangeRecorderInterface $recorder,
        private readonly RecordDiff $diff,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $group = $observer->getEvent()->getData('data_object');
            if (!$group instanceof Group) {
                return;
            }
            $verb = $this->verb($group, (string)$observer->getEvent()->getName());
            if ($verb !== null) {
                $this->recorder->record(
                    'customer_groups',
                    $verb,
                    ['group_id' => (int)$group->getId(), 'code' => (string)$group->getCode()]
                );
            }
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not record a customer group change.', ['exception' => $e]);
        }
    }

    private function verb(Group $group, string $eventName): ?EventVerb
    {
        if ($eventName === 'customer_group_delete_after') {
            return EventVerb::Deleted;
        }
        $original = (array)$group->getOrigData();
        // A group saved for the first time was never loaded, so it has nothing original.
        if (($original['customer_group_id'] ?? null) === null) {
            return EventVerb::Created;
        }

        return $this->diff->changedFields((array)$group->getData(), $original) === [] ? null : EventVerb::Updated;
    }
}
