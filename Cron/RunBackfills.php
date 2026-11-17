<?php

declare(strict_types=1);

namespace Centraliza\Connector\Cron;

use Centraliza\Connector\Backfill\BackfillService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps the whole-stream sends asked from the admin going, a few pages a minute.
 */
class RunBackfills
{
    /** Leaves room for the next run of a cron that fires every minute. */
    private const TIME_BUDGET_SECONDS = 40;

    /** Enough to stay well ahead of what the shared quota lets the consumers send. */
    private const MAX_RECORDS_PER_RUN = 10000;

    public function __construct(
        private readonly BackfillService $backfills,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $this->backfills->run(self::TIME_BUDGET_SECONDS, self::MAX_RECORDS_PER_RUN);
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not run the whole-stream sends.', ['exception' => $e]);
        }
    }
}
