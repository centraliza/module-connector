<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Http;

use Centraliza\Connector\Core\Model\ConnectionEvent;
use Centraliza\Connector\Core\Model\Outcome;
use Centraliza\Connector\Core\Port\TransportInterface;
use Centraliza\Connector\Infra\Config\Settings;

/**
 * Picks the transport per event, so switching the mode needs no deploy and
 * no consumer restart beyond the configuration cache.
 */
class SwitchingTransport implements TransportInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly DryRunTransport $dryRun,
        private readonly HttpTransport $http
    ) {
    }

    public function send(ConnectionEvent $event): Outcome
    {
        return $this->settings->isLive() ? $this->http->send($event) : $this->dryRun->send($event);
    }
}
