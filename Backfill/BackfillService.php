<?php

declare(strict_types=1);

namespace Centraliza\Connector\Backfill;

use Centraliza\Connector\Core\Model\ConnectionEvent;
use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Core\Port\ClockInterface;
use Centraliza\Connector\Core\Port\EventPublisherInterface;
use Centraliza\Connector\Core\Port\IdGeneratorInterface;
use Centraliza\Connector\Core\Stream\StreamRegistry;
use Centraliza\Connector\Infra\Config\Settings;
use InvalidArgumentException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends every record of a stream: the admin asks, and pages of keys go on the queue as "<stream>.updated"
 * events until the stream is exhausted. The consumers then read and send each record as for any change.
 *
 * The pages go on the bulk lane whatever the stream, so a send of every order never delays the orders being
 * placed now. The queue, not this service, keeps the pace of the requests (the shared quota).
 */
class BackfillService
{
    public const LANE = 'bulk';

    private const LOCK = 'centraliza_connector_backfill';
    private const PAGE = 500;

    /**
     * @param array<string, BackfillSourceInterface> $sources by stream, in the order the admin shows them
     */
    public function __construct(
        private readonly BackfillStore $store,
        private readonly Settings $settings,
        private readonly StreamRegistry $streams,
        private readonly EventPublisherInterface $publisher,
        private readonly IdGeneratorInterface $ids,
        private readonly ClockInterface $clock,
        private readonly LockManagerInterface $locks,
        private readonly LoggerInterface $logger,
        private readonly array $sources = []
    ) {
    }

    /**
     * @return string[] streams that can be sent whole
     */
    public function streams(): array
    {
        return array_keys($this->sources);
    }

    /**
     * How many records a send of the whole stream would put on the queue now.
     */
    public function count(string $stream): ?int
    {
        $websiteId = $this->settings->reportingWebsiteId();
        if ($websiteId === null || !isset($this->sources[$stream])) {
            return null;
        }

        return $this->sources[$stream]->count($websiteId);
    }

    /**
     * Asks for every record of the stream to be sent.
     *
     * @return array{id: int, total: int}
     * @throws LocalizedException when it cannot be asked now
     */
    public function request(string $stream, string $requestedBy): array
    {
        if (!isset($this->sources[$stream]) || !$this->streams->has($stream)) {
            throw new LocalizedException(__('Unknown stream "%1".', $stream));
        }
        $websiteId = $this->settings->reportingWebsiteId();
        if ($websiteId === null) {
            throw new LocalizedException(__('No website reports to Centraliza. Turn one on in the configuration first.'));
        }
        if ($this->store->active($stream) !== []) {
            throw new LocalizedException(__('A send of every %1 is already in progress.', $stream));
        }

        $total = $this->sources[$stream]->count($websiteId);

        return ['id' => $this->store->create($stream, $websiteId, $total, $requestedBy), 'total' => $total];
    }

    public function cancel(int $backfillId): bool
    {
        return $this->store->finish($backfillId, BackfillStore::CANCELLED);
    }

    /**
     * Puts the next pages of the active sends on the queue, oldest send first, until the budget runs out.
     * Another process already running sends makes this one return at once.
     *
     * @return int records put on the queue
     */
    public function run(int $maxSeconds, int $maxRecords): int
    {
        if (!$this->locks->lock(self::LOCK, 0)) {
            return 0;
        }
        $startedAt = $this->clock->now();
        $published = 0;
        try {
            foreach ($this->store->active() as $backfill) {
                $published += $this->advance($backfill, $startedAt + $maxSeconds, $maxRecords - $published);
                if ($published >= $maxRecords || $this->clock->now() >= $startedAt + $maxSeconds) {
                    break;
                }
            }
        } finally {
            $this->locks->unlock(self::LOCK);
        }

        return $published;
    }

    /**
     * @param array<string, mixed> $backfill
     * @return int records put on the queue
     */
    private function advance(array $backfill, int $deadline, int $budget): int
    {
        $id = (int)$backfill['backfill_id'];
        $stream = (string)$backfill['stream'];
        $websiteId = (int)$backfill['website_id'];
        $lastKey = (string)$backfill['last_key'];
        $source = $this->sources[$stream] ?? null;
        if ($source === null) {
            $this->store->finish($id, BackfillStore::FAILED, 'This stream can no longer be sent whole.');

            return 0;
        }
        if ($this->settings->reportingWebsiteId() !== $websiteId) {
            $this->store->finish($id, BackfillStore::FAILED, 'The reporting website changed after the send was asked.');

            return 0;
        }

        $published = 0;
        try {
            while ($published < $budget && $this->clock->now() < $deadline) {
                [$keys, $nextKey] = $source->next($websiteId, $lastKey, min(self::PAGE, $budget - $published));
                if ($keys === []) {
                    $this->store->finish($id, BackfillStore::DONE);
                    break;
                }
                $occurredAt = gmdate('Y-m-d\TH:i:s\Z', $this->clock->now());
                foreach ($keys as $key) {
                    try {
                        $event = new ConnectionEvent($stream, EventVerb::Updated, $this->streams->key($stream, $key), $this->ids->next(), $occurredAt);
                    } catch (InvalidArgumentException) {
                        // A record without a usable key (an empty SKU) cannot be sent; the rest of the page can.
                        continue;
                    }
                    $this->publisher->publish(self::LANE, $event);
                }
                $this->store->advance($id, $nextKey, count($keys));
                $lastKey = $nextKey;
                $published += count($keys);
            }
        } catch (Throwable $e) {
            // The page is put on the queue again on the next run: a record sent twice is only sent twice.
            $this->logger->error('Centraliza Connector could not put a page of a whole-stream send on the queue.', [
                'stream' => $stream,
                'exception' => $e,
            ]);
            $this->store->fail($id, $e::class . ': ' . $e->getMessage());
        }

        return $published;
    }
}
