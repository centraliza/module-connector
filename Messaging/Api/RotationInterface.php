<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api;

interface RotationInterface
{
    /**
     * Start the list at the current cron run's position, keeping the order of the rest.
     *
     * @param array $items
     * @param int $runIntervalMinutes how often the calling cron runs, e.g. 5 for a cron every 5 minutes
     * @return array a list (reindexed); empty for an empty input
     */
    public function rotate(array $items, int $runIntervalMinutes): array;
}
