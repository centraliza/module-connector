<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\CustomerGroup;

use Centraliza\Connector\Entity\ServiceRecord;
use Centraliza\Connector\Entity\StreamReaderInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Model\GroupRegistry;
use Magento\Framework\Exception\NoSuchEntityException;

class CustomerGroupReader implements StreamReaderInterface
{
    public function __construct(
        private readonly GroupRepositoryInterface $groups,
        private readonly GroupRegistry $registry,
        private readonly ServiceRecord $serviceRecord
    ) {
    }

    public function read(array $key): ?array
    {
        $groupId = (int)$key['group_id'];
        try {
            // The registry keeps what it loaded for the whole life of a queue consumer.
            $this->registry->remove($groupId);
            $group = $this->groups->getById($groupId);
        } catch (NoSuchEntityException) {
            return null;
        }
        $rest = $this->serviceRecord->toArray($group, GroupRepositoryInterface::class, 'getById');

        $record = [
            'id' => $rest['id'] ?? null,
            'code' => $rest['code'] ?? null,
            'tax_class' => ['id' => $rest['tax_class_id'] ?? null, 'name' => $rest['tax_class_name'] ?? null],
        ];

        // Whatever else the group has, such as what other modules attach to it.
        return $record + array_diff_key($rest, array_flip(['id', 'code', 'tax_class_id', 'tax_class_name']));
    }
}
