<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\AbandonedCart;

use Centraliza\Connector\Messaging\Api\CentralizaClientInterface;
use Centraliza\Connector\Messaging\Exception\CentralizaApiException;
use Centraliza\Connector\Messaging\Exception\CentralizaQuotaExceededException;
use Psr\Log\LoggerInterface;

class CartSender
{
    /**
     * Centraliza contract, relative to the Base URL, without a leading slash.
     */
    public const SEND_PATH = 'admin/events';

    /**
     * @param CentralizaClientInterface $client
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CentralizaClientInterface $client,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Post the payload to Centraliza.
     *
     * @param array $payload
     * @param int $websiteId
     * @return void
     * @throws CentralizaApiException when Centraliza did not accept the cart
     */
    public function send(array $payload, int $websiteId): void
    {
        try {
            $this->client->request('POST', self::SEND_PATH, ['json' => $payload], $websiteId);
        } catch (CentralizaApiException $exception) {
            // Not a failed send: a quota refusal sent nothing, and an unreadable 2xx was accepted.
            if ($exception instanceof CentralizaQuotaExceededException || $exception->wasAccepted()) {
                throw $exception;
            }

            // Never the payload: it carries the customer's name, telephone, e-mail and the masked id
            // that opens the cart.
            $this->logger->warning('Centraliza abandoned cart send failed', [
                'cart_id' => (int) ($payload['data']['cart']['id'] ?? 0),
                'website_id' => $websiteId,
                'http_status' => $exception->getHttpStatus(),
                'request_possibly_sent' => $exception->isRequestPossiblySent(),
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
