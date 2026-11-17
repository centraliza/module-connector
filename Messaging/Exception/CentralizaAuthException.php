<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Exception;

use Exception;
use Magento\Framework\Phrase;

class CentralizaAuthException extends CentralizaApiException
{
    public const HTTP_UNAUTHORIZED = 401;
    public const HTTP_FORBIDDEN = 403;

    /**
     * @param Phrase $phrase
     * @param int $httpStatus
     * @param Exception|null $cause
     * @param bool $requestPossiblySent
     */
    public function __construct(
        Phrase $phrase,
        int $httpStatus,
        ?Exception $cause = null,
        bool $requestPossiblySent = false
    ) {
        // A refused key never becomes valid by repeating the call, and each retry spends the shared quota.
        parent::__construct(
            $phrase,
            httpStatus: $httpStatus,
            retryable: false,
            cause: $cause,
            requestPossiblySent: $requestPossiblySent
        );
    }
}
