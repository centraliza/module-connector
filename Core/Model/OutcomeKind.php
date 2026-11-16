<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Model;

/**
 * How POST /v1/admin/events answered, per the Centraliza API reference.
 */
enum OutcomeKind: string
{
    /** 200: the event was stored, or its idempotency key had already been received. */
    case Accepted = 'accepted';
    /** 422: the body failed validation. */
    case Rejected = 'rejected';
    /** 429. */
    case RateLimited = 'rate_limited';
    /** 401: the key is missing, malformed or revoked. */
    case Unauthorized = 'unauthorized';
    /** 403/404: this key cannot use this route, or the connection does not exist in this workspace. */
    case RouteRefused = 'route_refused';
    /** 5xx, timeout or connection failure. */
    case Unavailable = 'unavailable';

    /** Centraliza answered as a healthy service, whatever it thought of the event. */
    public function provesServiceHealthy(): bool
    {
        return $this === self::Accepted || $this === self::Rejected;
    }
}
