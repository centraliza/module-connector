<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Api\Source;

use Centraliza\Connector\Messaging\Exception\CentralizaApiException;
use Centraliza\Connector\Messaging\Exception\CentralizaRateLimitException;
use Magento\Framework\Data\OptionSourceInterface;

class HttpStatus implements OptionSourceInterface
{
    /**
     * The statuses a Centraliza call usually ends with: success, the errors the Centraliza API
     * documents, the gateway answers the client treats as possibly delivered, and 599, which the
     * client records when the connection fails or the outcome is unknown. Any other status is
     * still recorded as received; it just has no entry here. Reason phrases stay in English, as
     * HTTP defines them: generic words such as "OK" already carry unrelated translations in the
     * language pack.
     */
    private const REASONS = [
        200 => 'OK',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        422 => 'Unprocessable Content',
        CentralizaRateLimitException::HTTP_STATUS => 'Too Many Requests',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        CentralizaApiException::CONNECTION_ERROR_CODE => 'Connection Failure',
    ];

    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach (self::REASONS as $status => $reason) {
            $options[] = ['value' => $status, 'label' => $status . ' ' . $reason];
        }

        return $options;
    }
}
