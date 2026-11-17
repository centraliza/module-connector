<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Telemetry;

use Centraliza\Connector\Core\Model\Action;
use Centraliza\Connector\Core\Model\HeldEvent;
use Centraliza\Connector\Core\Model\Outcome;
use Centraliza\Connector\Core\Port\DeliveryLogInterface;

final class NullDeliveryLog implements DeliveryLogInterface
{
    public function record(HeldEvent $held, Outcome $outcome, Action $action, int $sendMs): void
    {
    }
}
