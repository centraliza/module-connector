<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Api;

use Centraliza\Connector\Infra\Config\Settings;
use Centraliza\Connector\Messaging\Api\CentralizaClientInterface;
use Centraliza\Connector\Messaging\Api\ConfigInterface;
use Centraliza\Connector\Messaging\Api\QuotaReservationInterface;
use Centraliza\Connector\Messaging\Exception\CentralizaApiException;
use Centraliza\Connector\Messaging\Exception\CentralizaAuthException;
use Centraliza\Connector\Messaging\Exception\CentralizaQuotaExceededException;
use Centraliza\Connector\Messaging\Exception\CentralizaRateLimitException;
use Centraliza\Connector\Messaging\Exception\CentralizaRequestNotBuiltException;
use DateTimeImmutable;
use DateTimeInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\TransferStats;
use InvalidArgumentException;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;
use UnexpectedValueException;
use Zend_Db_Adapter_Exception;
use Zend_Db_Statement_Exception;

class Client implements CentralizaClientInterface
{
    private const STATUS_UNPROCESSABLE = 422;
    private const STATUS_SERVER_ERROR = 500;
    private const MAX_MESSAGE_LENGTH = 500;
    private const HEADER_RATE_LIMIT_REMAINING = 'X-RateLimit-Remaining';

    /**
     * Every consumer stores and logs Centraliza's error text, so a quoted csk_ key is masked once, here.
     */
    private const API_KEY_PATTERN = '/csk_[a-z0-9_-]+/i';
    private const API_KEY_MASK = 'csk_***';

    /**
     * Any other option (base_uri, proxy, debug, headers such as Host) could move or dump the request carrying the key.
     */
    private const ALLOWED_OPTIONS = ['json', 'query'];

    /**
     * The quota is 60 per minute (measured): a far higher header was never granted and would skip throttling.
     */
    private const MAX_TRUSTED_REMAINING = 1000;

    /**
     * curl errors raised before any byte was written (resolve, connect, TLS); used only when transfer info is missing.
     */
    private const CURL_ERRORS_BEFORE_SENDING = [
        CURLE_COULDNT_RESOLVE_PROXY,
        CURLE_COULDNT_RESOLVE_HOST,
        CURLE_COULDNT_CONNECT,
        CURLE_SSL_CONNECT_ERROR,
        CURLE_SSL_PEER_CERTIFICATE,
    ];

    /**
     * Written by a gateway that may have forwarded the POST before failing, so the outcome is unknown. A 599 a proxy
     * answered is one too: only a transport failure the client builds without a response may be "never sent".
     */
    private const GATEWAY_STATUSES = [502, 504, CentralizaApiException::CONNECTION_ERROR_CODE];

    private const NON_IDEMPOTENT_METHODS = ['POST', 'PATCH'];

    /**
     * Change nothing at Centraliza, so they go out in simulation mode too.
     */
    private const READ_METHODS = ['GET', 'HEAD'];
    private const REFUSED_STATUSES = [
        CentralizaAuthException::HTTP_UNAUTHORIZED,
        CentralizaAuthException::HTTP_FORBIDDEN,
    ];

    /**
     * A 429 blocks the key for one window (60 s, measured, user decision): a longer Retry-After is capped.
     */
    private const MAX_RETRY_AFTER_BLOCK_SECONDS = 60;

    /**
     * @param ClientFactory $clientFactory
     * @param Json $serializer
     * @param ConfigInterface $config
     * @param LoggerInterface $logger
     * @param QuotaReservationInterface $quotaReservation
     * @param Settings $settings
     * @param DryRunLog $dryRunLog
     * @param string $priority one of the QuotaReservationInterface::PRIORITY_* constants, set per virtual type
     */
    public function __construct(
        private readonly ClientFactory $clientFactory,
        private readonly Json $serializer,
        private readonly ConfigInterface $config,
        private readonly LoggerInterface $logger,
        private readonly QuotaReservationInterface $quotaReservation,
        private readonly Settings $settings,
        private readonly DryRunLog $dryRunLog,
        private readonly string $priority = QuotaReservationInterface::PRIORITY_TRANSACTIONAL
    ) {
    }

    /**
     * @param string $method
     * @param string $path
     * @param array $options
     * @param int|null $websiteId
     * @param string|null $apiKeyOverride
     * @return array
     * @throws CentralizaApiException
     */
    public function request(
        string $method,
        string $path,
        array $options = [],
        ?int $websiteId = null,
        #[SensitiveParameter] ?string $apiKeyOverride = null
    ): array {
        $relativePath = ltrim($path, '/');

        $this->assertSameOrigin($relativePath);
        $this->assertAllowedOptions($options);

        // Guzzle reports curl's transfer info once per try, so "nothing was sent" is judged over every try.
        $hasBody = isset($options['json']);
        $transfer = [
            'attempts' => 0,
            'complete' => true,
            'pretransfer_time' => 0.0,
            'size_upload' => 0.0,
            'unanswered_try_may_have_arrived' => false,
        ];
        $options[RequestOptions::ON_STATS] = static function (TransferStats $stats) use (&$transfer, $hasBody): void {
            $handlerStats = $stats->getHandlerStats();
            $pretransferTime = $handlerStats['pretransfer_time'] ?? null;
            $uploadedBytes = $handlerStats['size_upload'] ?? null;

            // Headers can arrive before the transfer fails, so only a response without curl error counts.
            $answered = $stats->hasResponse() && empty($stats->getHandlerErrorData());

            $transfer['attempts']++;
            if (!self::isNumber($pretransferTime) || !self::isNumber($uploadedBytes)) {
                $transfer['complete'] = false;
                $transfer['unanswered_try_may_have_arrived'] = $transfer['unanswered_try_may_have_arrived']
                    || !$answered;

                return;
            }

            $transfer['pretransfer_time'] = max($transfer['pretransfer_time'], (float) $pretransferTime);
            $transfer['size_upload'] = max($transfer['size_upload'], (float) $uploadedBytes);
            $transfer['unanswered_try_may_have_arrived'] = $transfer['unanswered_try_may_have_arrived']
                || (!$answered
                    && !self::showsNothingTransmitted((float) $pretransferTime, (float) $uploadedBytes, $hasBody));
        };

        try {
            $apiKey = $apiKeyOverride ?? $this->config->getApiKey($websiteId);
        } catch (Throwable $throwable) {
            throw $this->unreadableKey($throwable);
        }
        $this->assertUsableKey($apiKey);

        // Simulation mode (centraliza_connector/general/mode): a write is logged, never sent, and spends no
        // quota. A read (the key check) still goes out: it changes nothing at Centraliza.
        if (!$this->settings->isLive() && !in_array(strtoupper($method), self::READ_METHODS, true)) {
            return $this->simulate($method, $relativePath, $options);
        }

        $apiKeyHash = $this->config->hashApiKey($apiKey);
        $this->reserveQuota($apiKeyHash, $relativePath);

        // Building the HTTP client sends nothing, so its failure is never "possibly sent".
        try {
            $httpClient = $this->clientFactory->create($websiteId, $apiKey);
        } catch (Throwable $throwable) {
            throw $this->unusableSetup($throwable);
        }

        // In synchronous mode requestAsync() also sends the first try: only an InvalidArgumentException
        // raised while building the request means it was never sent.
        try {
            $promise = $httpClient->requestAsync(
                $method,
                $relativePath,
                $options + [RequestOptions::SYNCHRONOUS => true]
            );
        } catch (InvalidArgumentException) {
            // Not chained: the message can quote request data.
            throw $this->unbuildableRequest($method, $relativePath);
        } catch (Throwable $throwable) {
            // An Error here may come from the transport, so it reads as possibly sent.
            throw $this->unknownOutcome($method, $relativePath, $throwable);
        }

        try {
            $response = $promise->wait();
            if (!$response instanceof ResponseInterface) {
                throw new UnexpectedValueException('The handler stack resolved without a response.');
            }
        } catch (CentralizaApiException $exception) {
            throw $exception;
        } catch (GuzzleException $exception) {
            throw $this->transportFailure($method, $relativePath, $exception, $hasBody, $transfer);
        } catch (Throwable $throwable) {
            throw $this->unknownOutcome($method, $relativePath, $throwable);
        } finally {
            $this->recordRepeatedTriesSafely($apiKeyHash, $transfer['attempts']);
        }

        $status = $response->getStatusCode();
        $this->recordRateLimitSafely($response, $status, $apiKeyHash);

        if (in_array($status, self::REFUSED_STATUSES, true)) {
            $this->recordRefusalSafely($apiKeyHash, $relativePath, $apiKeyOverride !== null, $status);
        }

        if ($status < 200 || $status >= 300) {
            throw $this->buildException($response, $status, $transfer['unanswered_try_may_have_arrived']);
        }

        $this->clearRefusalSafely($apiKeyHash, $relativePath, $apiKeyOverride !== null);
        $this->warnOnRepeatedWrite($method, $relativePath, $transfer['attempts']);

        return $this->unwrap($response);
    }

    /**
     * Write the request to the simulation log and answer as Centraliza would with an empty body.
     *
     * @param string $method
     * @param string $relativePath
     * @param array $options
     * @return array
     * @throws CentralizaApiException when the log cannot be written: nothing was sent
     */
    private function simulate(string $method, string $relativePath, array $options): array
    {
        unset($options[RequestOptions::ON_STATS]);

        try {
            $this->dryRunLog->write($method, $this->config->getApiBaseUrl() . $relativePath, $options);
        } catch (Throwable $throwable) {
            $this->logger->error(
                'Centraliza simulation log could not be written',
                ['exception_class' => $throwable::class, 'origin' => $this->originOf($throwable)]
            );

            throw new CentralizaApiException(
                new Phrase('The Centraliza simulation log could not be written.'),
                httpStatus: 0,
                retryable: true,
                requestPossiblySent: false
            );
        }

        return [];
    }

    /**
     * Log when a non-idempotent call succeeded only after more than one try.
     *
     * @param string $method
     * @param string $relativePath
     * @param int $attempts
     * @return void
     */
    private function warnOnRepeatedWrite(string $method, string $relativePath, int $attempts): void
    {
        // Guzzle's curl handler resends any method by itself (errno 0 without response, errno 65): only made visible.
        if ($attempts <= 1 || !in_array(strtoupper($method), self::NON_IDEMPOTENT_METHODS, true)) {
            return;
        }

        $this->logger->warning(
            'Centraliza write succeeded after more than one try; a duplicate may have reached Centraliza',
            ['method' => $method, 'path' => $relativePath, 'attempts' => $attempts]
        );
    }

    /**
     * Reserve the first try in the key's shared quota, before anything is built or sent.
     *
     * @param string $apiKeyHash
     * @param string $relativePath
     * @return void
     * @throws CentralizaApiException
     */
    private function reserveQuota(string $apiKeyHash, string $relativePath): void
    {
        try {
            $reserved = $this->quotaReservation->reserve($apiKeyHash, $this->priority, $relativePath);
            if ($reserved) {
                return;
            }
            $waitSeconds = $this->quotaReservation->getWaitSeconds($apiKeyHash, $this->priority, $relativePath);
        } catch (InvalidArgumentException $exception) {
            // An unknown priority (DI) or an unusable route fails the same way on every call.
            throw $this->unusableSetup($exception);
        } catch (Throwable $throwable) {
            throw $this->unavailableQuota($throwable);
        }

        throw new CentralizaQuotaExceededException(
            new Phrase('The Centraliza request quota of this key is used up for now.'),
            max(1, $waitSeconds)
        );
    }

    /**
     * Log and build the error for a quota that could not be checked, before anything was sent.
     *
     * @param Throwable $throwable
     * @return CentralizaApiException
     */
    private function unavailableQuota(Throwable $throwable): CentralizaApiException
    {
        // Retryable: sending unreserved would let every process exceed the limit at once.
        $this->logger->error(
            'Centraliza request quota could not be checked',
            ['exception_class' => $throwable::class, 'origin' => $this->originOf($throwable)]
        );

        return new CentralizaApiException(
            new Phrase('The Centraliza request quota could not be checked.'),
            httpStatus: 0,
            retryable: true,
            requestPossiblySent: false
        );
    }

    /**
     * Count in the shared quota the automatic repetitions of this call.
     *
     * @param string $apiKeyHash
     * @param int $attempts
     * @return void
     */
    private function recordRepeatedTriesSafely(string $apiKeyHash, int $attempts): void
    {
        // Only the first try is reserved; the retry middleware's repetitions also count at Centraliza.
        if ($attempts <= 1) {
            return;
        }

        try {
            $this->quotaReservation->consume($apiKeyHash, $attempts - 1);
        } catch (Throwable $throwable) {
            $this->logger->warning(
                'Centraliza repeated tries could not be counted in the quota',
                ['exception_class' => $throwable::class]
            );
        }
    }

    /**
     * Pause the configured key (401) or the route (403) after Centraliza refused the call.
     *
     * @param string $apiKeyHash
     * @param string $relativePath
     * @param bool $keyIsOverride
     * @param int $httpStatus
     * @return void
     */
    private function recordRefusalSafely(
        string $apiKeyHash,
        string $relativePath,
        bool $keyIsOverride,
        int $httpStatus
    ): void {
        // A caller-supplied key (Authenticate button) was never saved, so it is not paused.
        if ($keyIsOverride) {
            return;
        }

        try {
            $this->quotaReservation->refuse($apiKeyHash, $relativePath, $httpStatus);
        } catch (Throwable $throwable) {
            $this->logger->warning(
                'Centraliza key refusal could not be recorded',
                ['exception_class' => $throwable::class]
            );
        }
    }

    /**
     * End a refusal after Centraliza accepted the call with a 2xx.
     *
     * @param string $apiKeyHash
     * @param string $relativePath
     * @param bool $keyIsOverride
     * @return void
     */
    private function clearRefusalSafely(string $apiKeyHash, string $relativePath, bool $keyIsOverride): void
    {
        // Of the calls with an override key, only the interactive check (Authenticate) confirms the key.
        if ($keyIsOverride && $this->priority !== QuotaReservationInterface::PRIORITY_INTERACTIVE) {
            return;
        }

        try {
            $this->quotaReservation->clearRefusal($apiKeyHash, $this->priority, $relativePath);
        } catch (Throwable $throwable) {
            $this->logger->warning(
                'Centraliza key refusal could not be cleared',
                ['exception_class' => $throwable::class]
            );
        }
    }

    /**
     * @param ResponseInterface $response
     * @param int $status
     * @param string $apiKeyHash
     * @return void
     */
    private function recordRateLimitSafely(ResponseInterface $response, int $status, string $apiKeyHash): void
    {
        // Centraliza already answered: a failure here must not replace that answer.
        try {
            $this->recordRateLimit($response, $status, $apiKeyHash);
        } catch (Throwable $throwable) {
            $this->logger->warning(
                'Centraliza rate limit reading could not be recorded',
                ['exception_class' => $throwable::class]
            );
        }
    }

    /**
     * @param ResponseInterface $response
     * @param int $status
     * @param string $apiKeyHash
     * @return void
     */
    private function recordRateLimit(ResponseInterface $response, int $status, string $apiKeyHash): void
    {
        // A 429 carries no X-RateLimit-Remaining (measured), yet it states that nothing is left.
        if ($status === CentralizaRateLimitException::HTTP_STATUS) {
            $retryAfter = $this->parseRetryAfter($response->getHeaderLine('Retry-After'));
            $this->quotaReservation->block(
                $apiKeyHash,
                min($retryAfter ?? self::MAX_RETRY_AFTER_BLOCK_SECONDS, self::MAX_RETRY_AFTER_BLOCK_SECONDS)
            );
            $this->quotaReservation->recordServerRemaining($apiKeyHash, 0);

            return;
        }

        $remaining = $response->getHeaderLine(self::HEADER_RATE_LIMIT_REMAINING);
        if (!ctype_digit($remaining)) {
            return;
        }

        $this->quotaReservation->recordServerRemaining($apiKeyHash, min((int) $remaining, self::MAX_TRUSTED_REMAINING));
    }

    /**
     * Refuse any path that resolves outside the configured Centraliza base URI.
     *
     * @param string $relativePath
     * @return void
     * @throws CentralizaApiException
     */
    private function assertSameOrigin(string $relativePath): void
    {
        // The key goes wherever the request resolves: "host.tld:443/x" has no scheme yet leaves the base URI.
        try {
            $baseUri = new Uri($this->config->getApiBaseUrl());
        } catch (Throwable $throwable) {
            throw $this->unusableSetup($throwable);
        }

        try {
            $relativeUri = new Uri($relativePath);
            $target = UriResolver::resolve($baseUri, $relativeUri);
        } catch (InvalidArgumentException) {
            throw $this->invalidPath();
        }

        // An encoded character or a dot segment spells the same URL another way, escaping the route's pause.
        $path = $relativeUri->getPath();
        if (str_contains($path, '%') || !empty(array_intersect(['.', '..'], explode('/', $path)))) {
            throw $this->invalidPath();
        }

        $sameOrigin = strcasecmp($target->getScheme(), $baseUri->getScheme()) === 0
            && strcasecmp($target->getHost(), $baseUri->getHost()) === 0
            && $target->getPort() === $baseUri->getPort()
            && str_starts_with($target->getPath(), $baseUri->getPath());

        if (!$sameOrigin) {
            throw $this->invalidPath();
        }
    }

    /**
     * Log and build the error for a request that could not be assembled, without the original message.
     *
     * @param string $method
     * @param string $relativePath
     * @return CentralizaRequestNotBuiltException
     */
    private function unbuildableRequest(string $method, string $relativePath): CentralizaRequestNotBuiltException
    {
        $this->logger->error(
            'Centraliza request could not be built',
            ['method' => $method, 'path' => $relativePath]
        );

        return new CentralizaRequestNotBuiltException(new Phrase('The Centraliza request could not be built.'));
    }

    /**
     * Log and build the error for a failure while reading the configured key, before anything was sent.
     *
     * @param Throwable $throwable
     * @return CentralizaApiException
     */
    private function unreadableKey(Throwable $throwable): CentralizaApiException
    {
        // Only a database failure may clear; an Encryptor failure repeats. Not chained: it can quote the stored value.
        $transient = $throwable instanceof Zend_Db_Adapter_Exception
            || $throwable instanceof Zend_Db_Statement_Exception
            || $throwable instanceof PDOException;

        $this->logger->error(
            'Centraliza API key could not be read',
            [
                'exception_class' => $throwable::class,
                'origin' => $this->originOf($throwable),
                'retryable' => $transient,
            ]
        );

        return new CentralizaApiException(
            new Phrase('The Centraliza API key could not be read.'),
            httpStatus: 0,
            retryable: $transient,
            requestPossiblySent: false
        );
    }

    /**
     * Log and build the error for a local failure while preparing the call, before anything was sent.
     *
     * @param Throwable $throwable
     * @return CentralizaApiException
     */
    private function unusableSetup(Throwable $throwable): CentralizaApiException
    {
        // Only the class is logged and the cause is not chained: ClientFactory's message can carry the key.
        $this->logger->error(
            'Centraliza client could not be prepared',
            ['exception_class' => $throwable::class, 'origin' => $this->originOf($throwable)]
        );

        return new CentralizaApiException(
            new Phrase('The Centraliza client could not be prepared.'),
            httpStatus: 0,
            retryable: false,
            requestPossiblySent: false
        );
    }

    /**
     * Log and build the error for a Guzzle transport failure.
     *
     * @param string $method
     * @param string $relativePath
     * @param GuzzleException $exception
     * @param bool $hasBody
     * @param array $transfer attempts and the largest pretransfer_time and size_upload over every try
     * @return CentralizaApiException
     */
    private function transportFailure(
        string $method,
        string $relativePath,
        GuzzleException $exception,
        bool $hasBody,
        array $transfer
    ): CentralizaApiException {
        $context = $this->handlerContext($exception);
        $errno = null;
        if (is_int($context['errno'] ?? null)) {
            $errno = $context['errno'];
        }
        $possiblySent = !$this->nothingSentInAnyTry($transfer, $context, $errno, $hasBody);

        // Not chained: the Guzzle exception holds the request, Authorization header included.
        $this->logger->error(
            'Centraliza API could not be reached',
            [
                'method' => $method,
                'path' => $relativePath,
                'exception_class' => $exception::class,
                'errno' => $errno,
                'origin' => $this->originOf($exception),
            ]
        );

        return new CentralizaApiException(
            new Phrase('Could not reach the Centraliza API.'),
            httpStatus: CentralizaApiException::CONNECTION_ERROR_CODE,
            retryable: true,
            requestPossiblySent: $possiblySent
        );
    }

    /**
     * @param GuzzleException $exception
     * @return array
     */
    private function handlerContext(GuzzleException $exception): array
    {
        if (!$exception instanceof ConnectException && !$exception instanceof RequestException) {
            return [];
        }

        return $exception->getHandlerContext();
    }

    /**
     * Whether the evidence shows that no try of this call put a request on the wire.
     *
     * @param array $transfer
     * @param array $context
     * @param int|null $errno
     * @param bool $hasBody
     * @return bool
     */
    private function nothingSentInAnyTry(array $transfer, array $context, ?int $errno, bool $hasBody): bool
    {
        // Counters reported for every try decide alone: the last errno cannot undo bytes an earlier try sent.
        if ($transfer['attempts'] > 0 && $transfer['complete']) {
            return self::showsNothingTransmitted($transfer['pretransfer_time'], $transfer['size_upload'], $hasBody);
        }

        // A try without curl info among several cannot be shown to have stayed off the wire.
        if ($transfer['attempts'] > 1) {
            return false;
        }

        if (in_array($errno, self::CURL_ERRORS_BEFORE_SENDING, true)) {
            return true;
        }

        $pretransferTime = null;
        if (self::isNumber($context['pretransfer_time'] ?? null)) {
            $pretransferTime = (float) $context['pretransfer_time'];
        }
        $uploadedBytes = null;
        if (self::isNumber($context['size_upload'] ?? null)) {
            $uploadedBytes = (float) $context['size_upload'];
        }

        return self::showsNothingTransmitted($pretransferTime, $uploadedBytes, $hasBody);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    /**
     * @param float|null $pretransferTime
     * @param float|null $uploadedBytes
     * @param bool $hasBody
     * @return bool
     */
    private static function showsNothingTransmitted(?float $pretransferTime, ?float $uploadedBytes, bool $hasBody): bool
    {
        // pretransfer_time 0: curl never started writing; size_upload 0 proves it only for a request with a body.
        if ($pretransferTime !== null && $pretransferTime <= 0) {
            return true;
        }

        return $hasBody && $uploadedBytes !== null && $uploadedBytes <= 0;
    }

    /**
     * @param Throwable $throwable
     * @return string
     */
    private function originOf(Throwable $throwable): string
    {
        return $throwable->getFile() . ':' . $throwable->getLine();
    }

    /**
     * Log and build the error for a failure raised while or after the request was sent.
     *
     * @param string $method
     * @param string $relativePath
     * @param Throwable $throwable
     * @return CentralizaApiException
     */
    private function unknownOutcome(string $method, string $relativePath, Throwable $throwable): CentralizaApiException
    {
        // Only the class is logged and the cause is not chained: the message can quote the key.
        $this->logger->error(
            'Centraliza request failed after it may have been sent',
            [
                'method' => $method,
                'path' => $relativePath,
                'exception_class' => $throwable::class,
                'origin' => $this->originOf($throwable),
            ]
        );

        return new CentralizaApiException(
            new Phrase('The Centraliza request failed and may have been sent.'),
            httpStatus: CentralizaApiException::CONNECTION_ERROR_CODE,
            retryable: true,
            requestPossiblySent: true
        );
    }

    /**
     * @return CentralizaApiException
     */
    private function invalidPath(): CentralizaApiException
    {
        return new CentralizaApiException(
            new Phrase('The Centraliza API path must be relative to the Centraliza API.'),
            httpStatus: 0,
            retryable: false
        );
    }

    /**
     * @param array $options
     * @return void
     * @throws CentralizaApiException
     */
    private function assertAllowedOptions(array $options): void
    {
        foreach (array_keys($options) as $option) {
            if (!in_array($option, self::ALLOWED_OPTIONS, true)) {
                throw new CentralizaApiException(
                    new Phrase('The Centraliza request option "%1" is not supported.', [(string) $option]),
                    httpStatus: 0,
                    retryable: false
                );
            }
        }
    }

    /**
     * @param string|null $apiKey
     * @return void
     * @throws CentralizaApiException
     */
    private function assertUsableKey(#[SensitiveParameter] ?string $apiKey): void
    {
        if (empty($apiKey)) {
            throw new CentralizaApiException(
                new Phrase('No Centraliza API key is configured for this scope.'),
                httpStatus: 0,
                retryable: false
            );
        }

        if (!preg_match(CentralizaClientInterface::SECRET_KEY_PATTERN, $apiKey)) {
            throw new CentralizaApiException(
                new Phrase('The Centraliza API key has an unexpected format.'),
                httpStatus: 0,
                retryable: false
            );
        }
    }

    /**
     * @param ResponseInterface $response
     * @return array
     * @throws CentralizaApiException
     */
    private function unwrap(ResponseInterface $response): array
    {
        // The 2xx status is kept: the request was processed even if the body is lost. Not chained: it quotes the body.
        try {
            $body = (string) $response->getBody();
            if ($body === '') {
                return [];
            }
            $decoded = $this->serializer->unserialize($body);
        } catch (Throwable $throwable) {
            $this->logger->error(
                'Centraliza API returned an unreadable body',
                ['status' => $response->getStatusCode(), 'exception_class' => $throwable::class]
            );

            throw new CentralizaApiException(
                new Phrase('Centraliza API returned an unreadable body.'),
                httpStatus: $response->getStatusCode(),
                retryable: false
            );
        }
        if (!is_array($decoded)) {
            return [];
        }

        // The Admin API answers in the JSON:API shape: callers get the data node.
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            return $decoded['data'];
        }

        return $decoded;
    }

    /**
     * @param ResponseInterface $response
     * @param int $status
     * @param bool $earlierTryMayHaveArrived an earlier try got no answer but may have reached Centraliza
     * @return CentralizaApiException
     */
    private function buildException(
        ResponseInterface $response,
        int $status,
        bool $earlierTryMayHaveArrived
    ): CentralizaApiException {
        // A redirect is not followed (the key would travel to the Location) and a 1xx is no final answer.
        if ($status < 200) {
            return new CentralizaApiException(
                new Phrase('Centraliza API answered with an informational status instead of a final answer.'),
                httpStatus: $status,
                retryable: false,
                requestPossiblySent: $earlierTryMayHaveArrived
            );
        }

        if ($status < 400) {
            return new CentralizaApiException(
                new Phrase('Centraliza API answered with a redirect, which is not followed.'),
                httpStatus: $status,
                retryable: false,
                requestPossiblySent: $earlierTryMayHaveArrived
            );
        }

        $message = $this->extractMessage($response);

        if ($status === CentralizaRateLimitException::HTTP_STATUS) {
            $retryAfter = $response->getHeaderLine('Retry-After');

            return new CentralizaRateLimitException(
                new Phrase($message),
                retryAfterSeconds: $this->parseRetryAfter($retryAfter),
                requestPossiblySent: $earlierTryMayHaveArrived
            );
        }

        if (in_array($status, self::REFUSED_STATUSES, true)) {
            return new CentralizaAuthException(
                new Phrase($message),
                httpStatus: $status,
                requestPossiblySent: $earlierTryMayHaveArrived
            );
        }

        return new CentralizaApiException(
            new Phrase($message),
            httpStatus: $status,
            retryable: $status >= self::STATUS_SERVER_ERROR,
            requestPossiblySent: $earlierTryMayHaveArrived || in_array($status, self::GATEWAY_STATUSES, true)
        );
    }

    /**
     * @param ResponseInterface $response
     * @return string
     */
    private function extractMessage(ResponseInterface $response): string
    {
        try {
            $body = (string) $response->getBody();
        } catch (Throwable) {
            return 'Centraliza API returned an unreadable error body.';
        }
        if ($body === '') {
            return 'Centraliza API returned an error without a body.';
        }

        // An outage answers with HTML: its decoding error must not replace the typed exception.
        try {
            $decoded = $this->serializer->unserialize($body);
        } catch (Throwable) {
            return 'Centraliza API returned an unreadable error body.';
        }
        if (!is_array($decoded)) {
            return 'Centraliza API returned an unreadable error body.';
        }

        // The body has no guaranteed shape and casting an array to string raises: scalars only.
        $message = 'Centraliza API returned an error.';
        if (isset($decoded['message']) && is_scalar($decoded['message'])) {
            $message = (string) $decoded['message'];
        }

        // A 422 names the offending fields in errors: without them nobody knows what to fix.
        $errors = $decoded['errors'] ?? null;
        if ($response->getStatusCode() === self::STATUS_UNPROCESSABLE && is_array($errors)) {
            $details = $this->describeValidationErrors($errors);
            if ($details !== []) {
                $message .= ' (' . implode('; ', $details) . ')';
            }
        }

        $message = (string) preg_replace(self::API_KEY_PATTERN, self::API_KEY_MASK, $message);

        // Untrusted content: bounded here so no caller's notice or log can be bloated.
        return mb_strimwidth($message, 0, self::MAX_MESSAGE_LENGTH, '…');
    }

    /**
     * @param array $errors
     * @return string[]
     */
    private function describeValidationErrors(array $errors): array
    {
        $details = [];
        foreach ($errors as $field => $problems) {
            $scalarProblems = array_filter(
                is_array($problems) ? $problems : [$problems],
                'is_scalar'
            );
            if ($scalarProblems === []) {
                continue;
            }

            $details[] = $field . ': ' . implode(' ', array_map('strval', $scalarProblems));
        }

        return $details;
    }

    /**
     * Retry-After as delta-seconds or an HTTP-date (RFC 7231).
     *
     * @param string $retryAfter
     * @return int|null
     */
    private function parseRetryAfter(string $retryAfter): ?int
    {
        if (ctype_digit($retryAfter)) {
            return (int) $retryAfter;
        }

        $date = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC7231, $retryAfter);
        if ($date === false) {
            return null;
        }

        return max(0, $date->getTimestamp() - time());
    }
}
