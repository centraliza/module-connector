<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api;

interface SelectAbandonedCartsServiceInterface
{
    /**
     * Record the website's qualifying carts as pending and return how many rows were created.
     *
     * @param int $websiteId
     * @return int
     */
    public function execute(int $websiteId): int;
}
