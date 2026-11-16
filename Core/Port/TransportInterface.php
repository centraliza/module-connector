<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Port;

use Centraliza\Connector\Core\Model\ConnectionEvent;
use Centraliza\Connector\Core\Model\Outcome;

interface TransportInterface
{
    /**
     * Reports one event to Centraliza and classifies the answer. Never throws.
     */
    public function send(ConnectionEvent $event): Outcome;
}
