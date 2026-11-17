<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api;

use Centraliza\Connector\Messaging\Exception\CentralizaApiException;
use SensitiveParameter;

interface CentralizaClientInterface
{
    /**
     * csk_ plus letters, digits, "_" or "-": anything else (a pasted line break) breaks the Authorization header,
     * and the HTTP library then quotes the whole key in its exception. D keeps $ from accepting a trailing newline.
     */
    public const SECRET_KEY_PATTERN = '/^csk_[A-Za-z0-9_-]+$/D';

    /**
     * A reservation still open after this long belongs to a dead process (the client times out at 15 s); chosen margin.
     */
    public const RESERVATION_TIMEOUT_MINUTES = 10;

    /**
     * Work cap per key and cron run, so one run never holds a key's lock for long (chosen, not measured).
     */
    public const MAX_REQUESTS_PER_KEY_RUN = 50;

    /**
     * Rows settled without a request cost no quota; ten per request still ends such a run (chosen bound).
     */
    public const MAX_RESERVATIONS_PER_KEY_RUN = self::MAX_REQUESTS_PER_KEY_RUN * 10;

    /**
     * Perform an authenticated call and return the unwrapped JSON:API data node.
     *
     * @param string $method
     * @param string $path relative, without a leading slash
     * @param array $options only json (the body) and query are accepted; any other key is refused
     * @param int|null $websiteId
     * @param string|null $apiKeyOverride a key to use instead of the configured one; its 401/403 never pauses the key
     *     or route, and on a non-interactive client its 2xx clears no pause either
     * @return array
     * @throws CentralizaApiException for every failure, including reading the configured key
     */
    public function request(
        string $method,
        string $path,
        array $options = [],
        ?int $websiteId = null,
        #[SensitiveParameter] ?string $apiKeyOverride = null
    ): array;
}
