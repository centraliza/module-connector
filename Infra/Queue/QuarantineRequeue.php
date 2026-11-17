<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Queue;

use Centraliza\Connector\Infra\Persistence\QuarantineStore;

/**
 * Puts every quarantined event back on the queue, starting from zero attempts, as
 * bin/magento centraliza:quarantine:retry --all does.
 */
class QuarantineRequeue
{
    private const PAGE = 500;

    public function __construct(
        private readonly QuarantineStore $quarantine,
        private readonly QueuePublisher $publisher
    ) {
    }

    /**
     * @return array{requeued: int, unreadable: int}
     */
    public function all(): array
    {
        $requeued = 0;
        $unreadable = 0;
        $afterId = 0;
        do {
            $rows = $this->quarantine->rows(self::PAGE, [], $afterId);
            $published = [];
            try {
                foreach ($rows as $row) {
                    $afterId = max($afterId, (int)$row['quarantine_id']);
                    $event = $this->quarantine->event($row);
                    if ($event === null) {
                        $unreadable++;
                        continue;
                    }
                    $this->publisher->publish((string)$row['lane'], $event);
                    $published[] = (int)$row['quarantine_id'];
                }
            } finally {
                // Whatever reached the queue leaves quarantine, even if a later publish failed.
                $requeued += $this->quarantine->remove($published);
            }
        } while (count($rows) === self::PAGE);

        return ['requeued' => $requeued, 'unreadable' => $unreadable];
    }
}
