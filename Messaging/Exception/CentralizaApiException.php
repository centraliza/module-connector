<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Exception;

use Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

class CentralizaApiException extends LocalizedException
{
    /**
     * Status of a transport failure: unassigned in the 5xx range, so consumers read it as "Centraliza unreachable".
     */
    public const CONNECTION_ERROR_CODE = 599;

    /**
     * @param Phrase $phrase
     * @param int $httpStatus
     * @param bool $retryable
     * @param Exception|null $cause
     * @param bool $requestPossiblySent
     */
    public function __construct(
        Phrase $phrase,
        private readonly int $httpStatus = 0,
        private readonly bool $retryable = false,
        ?Exception $cause = null,
        private readonly bool $requestPossiblySent = false
    ) {
        parent::__construct($phrase, $cause, $httpStatus);
    }

    /**
     * @return int
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return bool
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /**
     * Whether Centraliza may have processed the request without Magento getting its answer.
     * A resent POST could then deliver the same message twice.
     *
     * @return bool
     */
    public function isRequestPossiblySent(): bool
    {
        return $this->requestPossiblySent;
    }

    /**
     * A 2xx whose answer could not be read: Centraliza processed it, so it must never be resent.
     *
     * @return bool
     */
    public function wasAccepted(): bool
    {
        return $this->httpStatus >= 200 && $this->httpStatus < 300;
    }

    /**
     * Failed inside Magento, or failed to connect, before any request left it.
     *
     * @return bool
     */
    public function wasNeverSent(): bool
    {
        if ($this->requestPossiblySent) {
            return false;
        }

        // User decision: a connect or DNS failure provably sent nothing, so it releases without an attempt.
        return $this->httpStatus === 0 || $this->httpStatus === self::CONNECTION_ERROR_CODE;
    }

    /**
     * Centraliza down or unreachable: a 5xx, CONNECTION_ERROR_CODE included.
     *
     * @return bool
     */
    public function isUnavailable(): bool
    {
        return $this->httpStatus >= 500;
    }
}
