<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Model;

/**
 * The result of one delivery attempt, already classified by the transport.
 */
final readonly class Outcome
{
    public function __construct(
        public OutcomeKind $kind,
        public ?int $retryAfterSeconds = null,
        public string $detail = '',
        /** The HTTP status Centraliza answered with; null when there was no answer. */
        public ?int $httpStatus = null,
        /** False when the transport kept the event without making a request (no key, no quota left). */
        public bool $requested = true
    ) {
    }

    public static function of(OutcomeKind $kind, string $detail = ''): self
    {
        return new self($kind, null, $detail);
    }

    public static function rateLimited(?int $retryAfterSeconds): self
    {
        return new self(OutcomeKind::RateLimited, $retryAfterSeconds);
    }

    public static function unavailable(?int $retryAfterSeconds = null, string $detail = ''): self
    {
        return new self(OutcomeKind::Unavailable, $retryAfterSeconds, $detail);
    }

    /**
     * Nothing was sent: the transport kept the event back on its own.
     */
    public static function held(OutcomeKind $kind, ?int $retryAfterSeconds = null, string $detail = ''): self
    {
        return new self($kind, $retryAfterSeconds, $detail, null, false);
    }
}
