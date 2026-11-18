<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\ResourceModel\AbandonedCart;

use Centraliza\Connector\Messaging\Model\AbandonedCart\AbandonedCart;
use Centraliza\Connector\Messaging\Model\ResourceModel\AbandonedCart as AbandonedCartResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'entity_id';

    /**
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(AbandonedCart::class, AbandonedCartResource::class);
    }
}
