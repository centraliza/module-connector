<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Port;

use Centraliza\Connector\Core\Model\ConnectionEvent;

/**
 * Where an event ends up when delivery gave up on it. Nothing leaves
 * quarantine by itself: a person reviews it and sends it again or discards it.
 */
interface QuarantineStoreInterface
{
    /**
     * @param string $reason why delivery gave up, as last reported by the transport
     */
    public function quarantine(ConnectionEvent $event, string $lane, int $attempts, string $reason): void;
}
