<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Api;

use Centraliza\Connector\Messaging\Api\ConfigInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientFactory as GuzzleClientFactory;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

class ClientFactory
{
    /**
     * The platform allows 60 requests per minute per key, shared by every
     * Centraliza module. A low ceiling keeps one failing call from spending the
     * budget that the others need.
     */
    private const MAX_RETRIES = 3;
    private const BASE_DELAY_MILLISECONDS = 500;

    /**
     * Method, path, status and error only. The Authorization header and the key
     * must never reach the log file, at any level.
     */
    private const LOG_TEMPLATE = '{method} {target} {code} {error}';

    /**
     * Only methods that can be repeated without a second side effect are retried. A POST
     * that timed out may already have been processed - for the Notification module that
     * is a message already sent to a customer - and resending it is the caller's decision,
     * taken with its own record of what was delivered.
     *
     * This rule covers the retry middleware only. Guzzle's CurlFactory resends on its own,
     * for any method, when a try ends with no response and curl reported no error (errno 0),
     * or when curl could not rewind the body (errno 65), so in that narrow case a POST can
     * still be written to the wire again. Client logs a warning when that happens.
     */
    private const RETRYABLE_METHODS = ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS'];

    private const TIMEOUT_SECONDS = 15;
    private const CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * @param GuzzleClientFactory $guzzleClientFactory
     * @param ConfigInterface $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly GuzzleClientFactory $guzzleClientFactory,
        private readonly ConfigInterface $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param int|null $websiteId
     * @param string|null $apiKey the key to authenticate with; the scope's configured key when null
     * @return GuzzleClient
     */
    public function create(?int $websiteId = null, #[SensitiveParameter] ?string $apiKey = null): GuzzleClient
    {
        $stack = HandlerStack::create();
        $stack->push($this->authenticationMiddleware($websiteId, $apiKey));
        $stack->push(Middleware::retry($this->retryDecider(), $this->retryDelay()));
        // The request trace is opt-in: with one notification per order status change, a line
        // per call would grow with the store's order volume. Errors do not depend on it, the
        // client logs them itself.
        if ($this->config->isDebugEnabled()) {
            $stack->push(Middleware::log($this->logger, new MessageFormatter(self::LOG_TEMPLATE)));
        }

        return $this->guzzleClientFactory->create([
            'config' => [
                'base_uri' => $this->config->getApiBaseUrl($websiteId),
                'http_errors' => false,
                'allow_redirects' => false,
                'timeout' => self::TIMEOUT_SECONDS,
                'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
                'handler' => $stack,
            ],
        ]);
    }

    /**
     * @param int|null $websiteId
     * @param string|null $apiKey
     * @return callable
     */
    private function authenticationMiddleware(?int $websiteId, #[SensitiveParameter] ?string $apiKey): callable
    {
        $config = $this->config;
        $baseUri = new Uri($config->getApiBaseUrl($websiteId));

        // The key is fixed for the whole call, retries included: it is the one the caller
        // validated or the scope's configured one, resolved before the first attempt.
        return Middleware::mapRequest(
            static function (
                RequestInterface $request
            ) use (
                $config,
                $websiteId,
                $apiKey,
                $baseUri
            ): RequestInterface {
                $request = $request->withHeader('Accept', 'application/vnd.api+json, application/json');

                // Second line of defence behind Client::assertSameOrigin(): the key is only
                // ever attached to a request addressed to the Centraliza API origin. The host
                // alone is not enough: http:// on the same host would send the key in clear
                // text, and another port can be a different service.
                $target = $request->getUri();
                $sameOrigin = strcasecmp($target->getScheme(), $baseUri->getScheme()) === 0
                    && strcasecmp($target->getHost(), $baseUri->getHost()) === 0
                    && $target->getPort() === $baseUri->getPort();
                if (!$sameOrigin) {
                    return $request;
                }

                return $request->withHeader(
                    'Authorization',
                    'Bearer ' . (string) ($apiKey ?? $config->getApiKey($websiteId))
                );
            }
        );
    }

    /**
     * @return callable
     */
    private function retryDecider(): callable
    {
        return static function (
            int $retries,
            RequestInterface $request,
            ?ResponseInterface $response = null,
            ?Throwable $exception = null
        ): bool {
            if ($retries >= self::MAX_RETRIES) {
                return false;
            }

            if (!in_array(strtoupper($request->getMethod()), self::RETRYABLE_METHODS, true)) {
                return false;
            }

            if ($exception instanceof ConnectException) {
                return true;
            }

            // No 4xx is retried. 401 and 403 are a revoked or wrong-type key, and
            // 429 is surfaced to the caller instead of slept on here, because the
            // queue consumer that calls this is shared with the whole Magento async.
            return $response !== null && $response->getStatusCode() >= 500;
        };
    }

    /**
     * @return callable
     */
    private function retryDelay(): callable
    {
        return static fn (int $retries): int => self::BASE_DELAY_MILLISECONDS * (2 ** ($retries - 1));
    }
}
