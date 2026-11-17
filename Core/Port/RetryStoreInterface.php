<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Port;

use Centraliza\Connector\Core\Model\ConnectionEvent;
use Centraliza\Connector\Core\Model\HeldEvent;

/**
 * Where an event waits when it could not be delivered right away. Nothing is
 * written here on the happy path.
 */
interface RetryStoreInterface
{
    /**
     * Keeps the event until $dueAt. Holding the same event again replaces the earlier hold.
     *
     * @param int $dueAt unix seconds
     */
    public function hold(ConnectionEvent $event, string $lane, int $attempts, int $dueAt, string $lastError = ''): void;

    /**
     * Events whose wait is over, oldest first. They stay stored until forget().
     *
     * @return HeldEvent[]
     */
    public function due(int $now, int $limit): array;

    /**
     * @param string[] $idempotencyKeys
     */
    public function forget(array $idempotencyKeys): void;
}
