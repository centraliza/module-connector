<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Api;

use Centraliza\Connector\Messaging\Api\RotationInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

class Rotation implements RotationInterface
{
    private const SECONDS_PER_MINUTE = 60;

    /**
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @param array $items
     * @param int $runIntervalMinutes
     * @return array
     */
    public function rotate(array $items, int $runIntervalMinutes): array
    {
        // Without rotation the first key, website or type is always served first and is the only one
        // sent when the quota runs out mid-round. By run, not minute: a cron every 5 minutes would skip items.
        if (empty($items)) {
            return [];
        }

        $items = array_values($items);
        $minute = intdiv((int) $this->dateTime->gmtTimestamp(), self::SECONDS_PER_MINUTE);
        $run = intdiv($minute, max(1, $runIntervalMinutes));
        $start = $run % count($items);

        return [...array_slice($items, $start), ...array_slice($items, 0, $start)];
    }
}
