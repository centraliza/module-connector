<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Exception;

use Magento\Framework\Phrase;

/**
 * The request of one row could not be built (e.g. an unencodable payload): never sent, never retried.
 */
class CentralizaRequestNotBuiltException extends CentralizaApiException
{
    /**
     * @param Phrase $phrase
     */
    public function __construct(Phrase $phrase)
    {
        parent::__construct($phrase, httpStatus: 0, retryable: false, requestPossiblySent: false);
    }
}
