<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Port;

use Centraliza\Connector\Core\Model\Action;
use Centraliza\Connector\Core\Model\HeldEvent;
use Centraliza\Connector\Core\Model\Outcome;

/**
 * What was sent to Centraliza, one entry per request (or per simulated request in dry-run mode).
 */
interface DeliveryLogInterface
{
    /**
     * @param HeldEvent $held the event as it was taken from the queue
     * @param Outcome $outcome how Centraliza answered
     * @param Action $action what was done with the event afterwards
     * @param int $sendMs how long the request took
     *
     * Never throws: the log must not change what happens to the event.
     */
    public function record(HeldEvent $held, Outcome $outcome, Action $action, int $sendMs): void;
}
