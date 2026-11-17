<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Exception;

use Exception;
use Magento\Framework\Phrase;

class CentralizaRateLimitException extends CentralizaApiException
{
    public const HTTP_STATUS = 429;

    /**
     * @param Phrase $phrase
     * @param int|null $retryAfterSeconds
     * @param Exception|null $cause
     * @param bool $requestPossiblySent
     */
    public function __construct(
        Phrase $phrase,
        private readonly ?int $retryAfterSeconds = null,
        ?Exception $cause = null,
        bool $requestPossiblySent = false
    ) {
        parent::__construct(
            $phrase,
            httpStatus: self::HTTP_STATUS,
            retryable: true,
            cause: $cause,
            requestPossiblySent: $requestPossiblySent
        );
    }

    /**
     * @return int|null
     */
    public function getRetryAfterSeconds(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
