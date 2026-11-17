<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Exception;

use Magento\Framework\Phrase;

/**
 * No request was made: the shared quota refused the call or a 429 blocked the key.
 * Extends the 429 exception so existing 429 handling applies unchanged.
 */
class CentralizaQuotaExceededException extends CentralizaRateLimitException
{
    /**
     * @param Phrase $phrase
     * @param int|null $retryAfterSeconds
     */
    public function __construct(Phrase $phrase, ?int $retryAfterSeconds = null)
    {
        parent::__construct($phrase, retryAfterSeconds: $retryAfterSeconds, requestPossiblySent: false);
    }

    /**
     * @return bool
     */
    public function wasNeverSent(): bool
    {
        return true;
    }
}
