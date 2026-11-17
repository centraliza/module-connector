<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Customer;

use Magento\Framework\App\ResourceConnection;

/**
 * A customer as stored, addresses included. The repository saves a customer
 * model it never loaded, so the model cannot tell what changed; the database can.
 */
class CustomerSnapshot
{
    private const EAV_TYPES = ['datetime', 'decimal', 'int', 'text', 'varchar'];

    /** Bookkeeping of logins and password resets, not content of the customer. */
    private const VOLATILE = [
        'updated_at',
        'password_hash',
        'rp_token',
        'rp_token_created_at',
        'failures_num',
        'first_failure',
        'lock_expires',
        'session_cutoff',
    ];

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @return array{email: string, website_id: int, state: string}|null null when there is no such customer
     */
    public function of(int $customerId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName('customer_entity'))
                ->where('entity_id = ?', $customerId)
        );
        if (!$row) {
            return null;
        }

        $addresses = $connection->fetchAssoc(
            $connection->select()
                ->from($this->resource->getTableName('customer_address_entity'))
                ->where('parent_id = ?', $customerId)
                ->order('entity_id ASC')
        );
        foreach ($addresses as &$address) {
            unset($address['updated_at']);
        }
        unset($address);

        return [
            'email' => (string)$row['email'],
            'website_id' => (int)$row['website_id'],
            'state' => hash('xxh128', (string)json_encode([
                array_diff_key($row, array_flip(self::VOLATILE)),
                $this->attributeValues('customer_entity', [$customerId]),
                $addresses,
                $this->attributeValues('customer_address_entity', array_map('intval', array_keys($addresses))),
            ])),
        ];
    }

    /**
     * @param int[] $entityIds
     * @return array<int, array<string, mixed>>
     */
    private function attributeValues(string $entityTable, array $entityIds): array
    {
        if ($entityIds === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $values = [];
        foreach (self::EAV_TYPES as $type) {
            $values = array_merge($values, $connection->fetchAll(
                $connection->select()
                    ->from($this->resource->getTableName($entityTable . '_' . $type), ['entity_id', 'attribute_id', 'value'])
                    ->where('entity_id IN (?)', $entityIds)
                    ->order(['entity_id ASC', 'attribute_id ASC'])
            ));
        }

        return $values;
    }
}
