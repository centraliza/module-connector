<?php

declare(strict_types=1);

namespace Centraliza\Connector\Capture;

use Centraliza\Connector\Core\Model\ConnectionEvent;
use Centraliza\Connector\Core\Model\EventVerb;
use Centraliza\Connector\Core\Port\ChangeRecorderInterface;
use Centraliza\Connector\Core\Port\ClockInterface;
use Centraliza\Connector\Core\Port\EventPublisherInterface;
use Centraliza\Connector\Core\Port\IdGeneratorInterface;
use Centraliza\Connector\Core\Port\SettingsInterface;
use Centraliza\Connector\Core\Port\TransactionStateInterface;
use Centraliza\Connector\Core\Stream\StreamRegistry;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Holds what the hooks recorded until the database transaction commits, then
 * publishes one event per record. A rollback throws the buffer away.
 */
class Collector implements ChangeRecorderInterface
{
    /** A record deleted in a transaction is reported as deleted, whatever else happened to it there. */
    private const PRECEDENCE = ['updated' => 1, 'created' => 2, 'deleted' => 3];

    /** @var array<string, array{stream: string, verb: EventVerb, key: array<string, scalar>}> */
    private array $buffer = [];

    private bool $flushing = false;

    public function __construct(
        private readonly StreamRegistry $streams,
        private readonly SettingsInterface $settings,
        private readonly TransactionStateInterface $transaction,
        private readonly EventPublisherInterface $publisher,
        private readonly IdGeneratorInterface $ids,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    public function record(string $stream, EventVerb $verb, array $key): void
    {
        try {
            if (!$this->settings->isEnabled() || !$this->streams->has($stream)) {
                return;
            }
            $key = $this->streams->key($stream, $key);
            $id = $stream . "\x1f" . implode("\x1f", array_map('strval', $key));
            $known = $this->buffer[$id]['verb'] ?? null;

            if ($known === null || self::PRECEDENCE[$verb->value] > self::PRECEDENCE[$known->value]) {
                $this->buffer[$id] = ['stream' => $stream, 'verb' => $verb, 'key' => $key];
            }
            if (!$this->transaction->inTransaction()) {
                $this->flush();
            }
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not record a change.', ['exception' => $e]);
        }
    }

    /**
     * Publishes everything recorded so far. Called when the outermost transaction commits.
     */
    public function flush(): void
    {
        if ($this->buffer === [] || $this->flushing) {
            return;
        }
        $this->flushing = true;
        $pending = $this->buffer;
        $this->buffer = [];

        try {
            $occurredAt = gmdate('Y-m-d\TH:i:s\Z', $this->clock->now());
            foreach ($pending as $change) {
                try {
                    $this->publisher->publish(
                        $this->streams->lane($change['stream']),
                        new ConnectionEvent(
                            $change['stream'],
                            $change['verb'],
                            $change['key'],
                            $this->ids->next(),
                            $occurredAt
                        )
                    );
                } catch (Throwable $e) {
                    // The data is already committed; the request of the merchant must go on.
                    $this->logger->error('Centraliza Connector lost an event.', [
                        'stream' => $change['stream'],
                        'verb' => $change['verb']->value,
                        'exception' => $e,
                    ]);
                }
            }
        } finally {
            $this->flushing = false;
        }
    }

    /**
     * Forgets everything recorded so far. Called when the transaction rolls back.
     */
    public function discard(): void
    {
        $this->buffer = [];
    }
}
