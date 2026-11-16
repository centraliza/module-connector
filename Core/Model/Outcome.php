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
        public string $detail = ''
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
}
