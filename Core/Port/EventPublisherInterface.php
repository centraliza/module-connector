<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Port;

use Centraliza\Connector\Core\Model\ConnectionEvent;

interface EventPublisherInterface
{
    /**
     * Puts the event on the queue of its lane.
     *
     * @param int $attempts failed delivery attempts so far
     */
    public function publish(string $lane, ConnectionEvent $event, int $attempts = 0): void;
}
