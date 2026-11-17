<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Puts next to an id the name it stands for, so a record can be read without
 * this store's tables at hand.
 */
class Names
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @return array{id: int, code: string|null}
     */
    public function website(int $websiteId): array
    {
        try {
            $code = (string)$this->storeManager->getWebsite($websiteId)->getCode();
        } catch (NoSuchEntityException) {
            $code = null;
        }

        return ['id' => $websiteId, 'code' => $code];
    }

    /**
     * @return array{id: int, code: string|null}
     */
    public function store(int $storeId): array
    {
        try {
            $code = (string)$this->storeManager->getStore($storeId)->getCode();
        } catch (NoSuchEntityException) {
            $code = null;
        }

        return ['id' => $storeId, 'code' => $code];
    }

    /**
     * Read from the table on purpose: the group registry keeps what it loaded
     * for the whole life of a queue consumer.
     *
     * @return array{id: int, code: string|null}
     */
    public function customerGroup(int $groupId): array
    {
        $connection = $this->resource->getConnection();
        $code = (string)$connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('customer_group'), ['customer_group_code'])
                ->where('customer_group_id = ?', $groupId)
        );

        return ['id' => $groupId, 'code' => $code === '' ? null : $code];
    }
}
