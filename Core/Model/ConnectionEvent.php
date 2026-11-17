<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Model;

use InvalidArgumentException;

/**
 * A connection event as POST /v1/admin/events expects it: the type names the
 * stream and what happened, and data carries the key of the record and, when
 * it was read, the record itself.
 */
final readonly class ConnectionEvent
{
    /** Identifies this module as the reporter; must match ^[a-z0-9][a-z0-9_-]*$ and fit in 64 characters. */
    public const SOURCE = 'magento';
    /** Where the record goes inside data, next to the key fields. */
    public const RECORD_FIELD = 'record';
    private const STREAM_PATTERN = '/^[a-z][a-z0-9_]*$/';
    private const MAX_TYPE_LENGTH = 96;
    private const MAX_IDEMPOTENCY_KEY_LENGTH = 191;

    /**
     * @param array<string, scalar> $key the record's key fields, e.g. ['sku' => 'A1', 'source_code' => 'default']
     * @param array<string, mixed>|null $record the record as it is now; null for a delete and before it is read
     */
    public function __construct(
        public string $stream,
        public EventVerb $verb,
        public array $key,
        public string $idempotencyKey,
        public string $occurredAt,
        public ?array $record = null
    ) {
        if (preg_match(self::STREAM_PATTERN, $stream) !== 1) {
            throw new InvalidArgumentException('Invalid stream name.');
        }
        if (strlen($this->type()) > self::MAX_TYPE_LENGTH) {
            throw new InvalidArgumentException('Event type is too long.');
        }
        if ($key === []) {
            throw new InvalidArgumentException('A connection event needs at least one key field.');
        }
        if ($idempotencyKey === '' || strlen($idempotencyKey) > self::MAX_IDEMPOTENCY_KEY_LENGTH) {
            throw new InvalidArgumentException('Invalid idempotency key.');
        }
    }

    public function type(): string
    {
        return $this->stream . '.' . $this->verb->value;
    }

    /**
     * The same event carrying the record. The record is read when the event is
     * sent, not when the change is captured, so a retry sends the current state.
     *
     * @param array<string, mixed> $record
     */
    public function withRecord(array $record): self
    {
        return new self($this->stream, $this->verb, $this->key, $this->idempotencyKey, $this->occurredAt, $record);
    }

    /**
     * @return array<string, mixed> the JSON body, without a contact
     */
    public function toRequestBody(string $connectionId): array
    {
        return [
            'connection' => $connectionId,
            'source' => self::SOURCE,
            'type' => $this->type(),
            'idempotency_key' => $this->idempotencyKey,
            'occurred_at' => $this->occurredAt,
            'data' => $this->record === null ? $this->key : $this->key + [self::RECORD_FIELD => $this->record],
        ];
    }
}
