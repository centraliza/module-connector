<?php

declare(strict_types=1);

namespace Centraliza\Connector\Cron;

use Centraliza\Connector\Infra\Persistence\DeliveryLog;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deletes the sent-requests log older than DeliveryLog::RETENTION_DAYS.
 */
class CleanDeliveryLog
{
    public function __construct(
        private readonly DeliveryLog $log,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $this->log->deleteOld();
        } catch (Throwable $e) {
            $this->logger->warning('Centraliza Connector could not clean the delivery log.', ['exception' => $e]);
        }
    }
}
