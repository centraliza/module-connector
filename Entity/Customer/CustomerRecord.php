<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity\Customer;

use Centraliza\Connector\Entity\AttributeLabels;
use Centraliza\Connector\Entity\Names;

/**
 * Arranges a customer as the REST API answers it: ids come with the name
 * they stand for and custom attributes become fields named after their code.
 */
class CustomerRecord
{
    private const FIRST = ['id', 'email', 'prefix', 'firstname', 'middlename', 'lastname', 'suffix', 'dob', 'taxvat'];
    private const DATES = ['created_at', 'updated_at', 'created_in', 'default_billing', 'default_shipping'];
    private const PLACED = [
        'gender', 'group_id', 'website_id', 'store_id', 'addresses', 'custom_attributes', 'extension_attributes',
    ];

    public function __construct(
        private readonly Names $names,
        private readonly AttributeLabels $labels
    ) {
    }

    /**
     * @param array<string, mixed> $rest the customer as the REST API answers it, custom attributes by code
     * @return array<string, mixed>
     */
    public function arrange(array $rest, int $storeId): array
    {
        $record = array_intersect_key($rest, array_flip(self::FIRST));
        $record = array_merge(array_intersect_key(array_flip(self::FIRST), $record), $record);
        if (array_key_exists('gender', $rest)) {
            $record['gender'] = $this->labels->labeled('customer', 'gender', $rest['gender'], $storeId);
        }
        if (isset($rest['group_id'])) {
            $record['group'] = $this->names->customerGroup((int)$rest['group_id']);
        }
        if (isset($rest['website_id'])) {
            $record['website'] = $this->names->website((int)$rest['website_id']);
        }
        if (isset($rest['store_id'])) {
            $record['store'] = $this->names->store((int)$rest['store_id']);
        }
        $record += array_intersect_key($rest, array_flip(self::DATES));

        $record['addresses'] = array_map(
            fn (array $address): array => $this->address($address, $storeId),
            array_values(array_filter((array)($rest['addresses'] ?? []), 'is_array'))
        );
        $record['attributes'] = $this->labels->withLabels('customer', (array)($rest['custom_attributes'] ?? []), $storeId);
        $record += array_diff_key($rest, array_flip(array_merge(self::FIRST, self::DATES, self::PLACED)));
        if (!empty($rest['extension_attributes'])) {
            $record['extension_attributes'] = $rest['extension_attributes'];
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $address
     * @return array<string, mixed>
     */
    private function address(array $address, int $storeId): array
    {
        $attributes = (array)($address['custom_attributes'] ?? []);
        unset($address['custom_attributes']);
        $address['default_billing'] = (bool)($address['default_billing'] ?? false);
        $address['default_shipping'] = (bool)($address['default_shipping'] ?? false);
        $address['attributes'] = $this->labels->withLabels('customer_address', $attributes, $storeId);

        return $address;
    }
}
