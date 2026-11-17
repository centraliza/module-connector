<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Http;

use Centraliza\Connector\Core\Model\ConnectionEvent;
use Centraliza\Connector\Core\Model\Outcome;
use Centraliza\Connector\Core\Model\OutcomeKind;
use Centraliza\Connector\Core\Port\ClockInterface;
use Centraliza\Connector\Core\Port\TransportInterface;
use Centraliza\Connector\Infra\Config\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * POST /v1/admin/events, one event per request, inside the request quota the key shares with the messages.
 */
class HttpTransport implements TransportInterface
{
    private const PATH = '/v1/admin/events';
    private const CONNECT_TIMEOUT = 3;
    private const TIMEOUT = 10;
    private const DETAIL_LENGTH = 300;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly Settings $settings,
        private readonly ClockInterface $clock,
        private readonly SharedQuota $quota
    ) {
    }

    public function send(ConnectionEvent $event): Outcome
    {
        $apiKey = $this->settings->apiKey();
        $connectionId = $this->settings->connectionId();
        $baseUrl = $this->settings->baseUrl();
        if ($apiKey === '' || $connectionId === '' || $baseUrl === '') {
            // Held like a refused key: nothing is sent and no attempt is spent until it is configured.
            return Outcome::held(OutcomeKind::Unauthorized, null, 'The API key or the connection id is not configured.');
        }

        try {
            $wait = $this->quota->take($apiKey);
        } catch (Throwable $e) {
            // Sending unreserved would let every consumer exceed the limit at once.
            return Outcome::held(OutcomeKind::Unavailable, null, 'The shared request quota could not be checked: ' . $e::class);
        }
        if ($wait > 0) {
            // Used up for now, or the key is paused: no attempt is spent.
            return Outcome::held(OutcomeKind::RateLimited, $wait, 'Shared request quota used up or paused.');
        }

        try {
            $response = $this->client->request('POST', $baseUrl . self::PATH, [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Accept' => 'application/json',
                ],
                RequestOptions::JSON => $event->toRequestBody($connectionId),
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::CONNECT_TIMEOUT => self::CONNECT_TIMEOUT,
                RequestOptions::TIMEOUT => self::TIMEOUT,
            ]);
        } catch (Throwable $e) {
            // The class only: a Guzzle message can quote the request, and the request carries the key.
            return Outcome::unavailable(null, 'No answer: ' . $e::class);
        }

        $this->quota->record($apiKey, $response);

        return $this->classify($response);
    }

    private function classify(ResponseInterface $response): Outcome
    {
        $status = $response->getStatusCode();
        $detail = 'HTTP ' . $status;

        return match (true) {
            $status >= 200 && $status < 300 => new Outcome(OutcomeKind::Accepted, null, $detail, $status),
            $status === 400 || $status === 422 => new Outcome(OutcomeKind::Rejected, null, $detail . $this->message($response), $status),
            $status === 429 => new Outcome(OutcomeKind::RateLimited, $this->retryAfter($response), $detail, $status),
            $status === 401 => new Outcome(OutcomeKind::Unauthorized, null, $detail, $status),
            $status === 403 || $status === 404 => new Outcome(OutcomeKind::RouteRefused, null, $detail . $this->message($response), $status),
            default => new Outcome(OutcomeKind::Unavailable, $this->retryAfter($response), $detail, $status),
        };
    }

    /**
     * The validation message of the answer, cut short. The answer never carries the key.
     */
    private function message(ResponseInterface $response): string
    {
        try {
            $body = trim(substr((string)$response->getBody(), 0, 4096));
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $body = (string)json_encode(
                    array_intersect_key($decoded, ['message' => 1, 'errors' => 1, 'error' => 1, 'code' => 1]),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }
        } catch (Throwable) {
            return '';
        }

        return $body === '' ? '' : ': ' . mb_substr($body, 0, self::DETAIL_LENGTH);
    }

    /**
     * Retry-After as delta-seconds or an HTTP-date (RFC 9110).
     */
    private function retryAfter(ResponseInterface $response): ?int
    {
        $value = trim($response->getHeaderLine('Retry-After'));
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int)$value;
        }
        $date = strtotime($value);

        return $date === false ? null : max(0, $date - $this->clock->now());
    }
}
