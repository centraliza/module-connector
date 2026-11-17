<?php

declare(strict_types=1);

namespace Centraliza\Connector\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Stream implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'products', 'label' => __('Products')],
            ['value' => 'prices', 'label' => __('Prices')],
            ['value' => 'stock', 'label' => __('Stock')],
            ['value' => 'locations', 'label' => __('Sources')],
            ['value' => 'customers', 'label' => __('Customers')],
            ['value' => 'customer_groups', 'label' => __('Customer groups')],
            ['value' => 'orders', 'label' => __('Orders')],
            ['value' => 'carts', 'label' => __('Carts')],
        ];
    }
}
