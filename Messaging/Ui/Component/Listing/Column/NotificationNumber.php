<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;

class NotificationNumber extends Column
{
    /**
     * Show reminder_number as the cart's notification number, counted from 1.
     *
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (empty($dataSource['data']['items'])) {
            return $dataSource;
        }

        $name = $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            // The column stores 0 for the first message, so the store owner reads 1, 2, 3...
            $item[$name] = (int) ($item[$name] ?? 0) + 1;
        }
        unset($item);

        return $dataSource;
    }
}
