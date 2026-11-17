<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Auth;

use Centraliza\Connector\Messaging\Api\CentralizaClientInterface;
use Centraliza\Connector\Messaging\Api\ConfigInterface;
use Centraliza\Connector\Messaging\Api\ConnectionValidatorInterface;
use Centraliza\Connector\Messaging\Api\Data\ConnectionResultInterface;
use Centraliza\Connector\Messaging\Api\QuotaReservationInterface;
use Centraliza\Connector\Messaging\Exception\CentralizaApiException;
use Centraliza\Connector\Messaging\Exception\CentralizaAuthException;
use Centraliza\Connector\Messaging\Exception\CentralizaRateLimitException;
use Centraliza\Connector\Messaging\Model\Data\ConnectionResultFactory;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

class ConnectionValidator implements ConnectionValidatorInterface
{
    /**
     * The API answers a publishable cpk_ key with a generic 403, so it is refused locally, spending no request.
     */
    private const SECRET_KEY_PREFIX = 'csk_';
    private const PUBLISHABLE_KEY_PREFIX = 'cpk_';

    private const WORKSPACE_PATH = 'admin/workspace';

    /**
     * Hashed with the website id to name the scope's own check stamp, a row apart from every key's.
     */
    private const SCOPE_CHECK_PREFIX = 'authenticate:';

    /**
     * @param CentralizaClientInterface $client
     * @param ConnectionResultFactory $resultFactory
     * @param LoggerInterface $logger
     * @param ConfigInterface $config
     * @param QuotaReservationInterface $quotaReservation
     */
    public function __construct(
        private readonly CentralizaClientInterface $client,
        private readonly ConnectionResultFactory $resultFactory,
        private readonly LoggerInterface $logger,
        private readonly ConfigInterface $config,
        private readonly QuotaReservationInterface $quotaReservation
    ) {
    }

    /**
     * @param string $apiKey
     * @param int|null $websiteId
     * @param bool $isStoredKey
     * @return ConnectionResultInterface
     */
    public function validate(
        #[SensitiveParameter] string $apiKey,
        ?int $websiteId = null,
        bool $isStoredKey = false
    ): ConnectionResultInterface {
        $trimmedKey = trim($apiKey);

        if ($trimmedKey === '') {
            return $this->failure((string) __('Enter the API Key before authenticating.'));
        }

        if (str_starts_with($trimmedKey, self::PUBLISHABLE_KEY_PREFIX)) {
            return $this->failure(
                (string) __('This is a publishable storefront key. Enter the secret key, which starts with csk_.')
            );
        }

        if (!str_starts_with($trimmedKey, self::SECRET_KEY_PREFIX)) {
            return $this->failure(
                (string) __('A Centraliza secret key starts with csk_. Check the value copied from the panel.')
            );
        }

        if (!preg_match(CentralizaClientInterface::SECRET_KEY_PATTERN, $trimmedKey)) {
            return $this->failure(
                (string) __('The key has unexpected characters. Copy it again from the Centraliza panel.')
            );
        }

        // Stamped in the database after the local checks, shared by every admin and parallel request. A typed key
        // also claims the scope's stamp, so typing another key does not skip the wait.
        $apiKeyHash = $this->config->hashApiKey($trimmedKey);
        $checkHashes = [];
        if (!$isStoredKey) {
            $checkHashes[] = $this->config->hashApiKey(self::SCOPE_CHECK_PREFIX . (int) $websiteId);
        }
        $checkHashes[] = $apiKeyHash;

        try {
            $waitSeconds = $this->claimChecks($checkHashes);
        } catch (Throwable $throwable) {
            return $this->unavailableQuota($throwable);
        }
        if ($waitSeconds > 0) {
            return $this->throttled($waitSeconds);
        }

        try {
            $workspace = $this->client->request(
                'GET',
                self::WORKSPACE_PATH,
                [],
                $websiteId,
                $trimmedKey
            );
        } catch (CentralizaAuthException) {
            return $this->failure(
                (string) __('Centraliza rejected the key. Check whether it was revoked in the panel.'),
                true
            );
        } catch (CentralizaRateLimitException $exception) {
            return $this->rateLimited($exception, $apiKeyHash);
        } catch (CentralizaApiException $exception) {
            $this->logger->error('Centraliza connection validation failed', ['status' => $exception->getHttpStatus()]);

            return $this->unreachable();
        }

        $workspaceId = null;
        if (isset($workspace['id']) && is_scalar($workspace['id'])) {
            $workspaceId = (string) $workspace['id'];
        }
        $attributes = $workspace['attributes'] ?? [];
        $workspaceName = null;
        if (isset($attributes['name']) && is_scalar($attributes['name'])) {
            $workspaceName = (string) $attributes['name'];
        }

        return $this->resultFactory->create([
            'valid' => true,
            'workspaceId' => $workspaceId,
            'workspaceName' => $workspaceName,
        ]);
    }

    /**
     * Claim every check stamp, or none while one of them still has a wait.
     *
     * @param string[] $checkHashes
     * @return int seconds to wait, 0 once every stamp was claimed
     */
    private function claimChecks(array $checkHashes): int
    {
        $waitSeconds = 0;
        foreach ($checkHashes as $checkHash) {
            $waitSeconds = max($waitSeconds, $this->quotaReservation->getCheckWaitSeconds($checkHash));
        }
        if ($waitSeconds > 0) {
            return $waitSeconds;
        }

        foreach ($checkHashes as $checkHash) {
            // Another process claimed it after the read.
            if (!$this->quotaReservation->claimCheck($checkHash)) {
                return max(1, $this->quotaReservation->getCheckWaitSeconds($checkHash));
            }
        }

        return 0;
    }

    /**
     * @param CentralizaRateLimitException $exception
     * @param string $apiKeyHash
     * @return ConnectionResultInterface
     */
    private function rateLimited(CentralizaRateLimitException $exception, string $apiKeyHash): ConnectionResultInterface
    {
        $waitSeconds = $exception->getRetryAfterSeconds();
        if (!empty($waitSeconds)) {
            return $this->throttled($waitSeconds);
        }

        // A 429 without Retry-After: the key's block and its check stamp tell how long to wait.
        try {
            $waitSeconds = max(
                $this->quotaReservation->getWaitSeconds(
                    $apiKeyHash,
                    QuotaReservationInterface::PRIORITY_INTERACTIVE,
                    self::WORKSPACE_PATH
                ),
                $this->quotaReservation->getCheckWaitSeconds($apiKeyHash)
            );
        } catch (Throwable $throwable) {
            return $this->unavailableQuota($throwable);
        }

        return $this->throttled($waitSeconds);
    }

    /**
     * @param Throwable $throwable
     * @return ConnectionResultInterface
     */
    private function unavailableQuota(Throwable $throwable): ConnectionResultInterface
    {
        // Only the class and origin: a database message can quote the query.
        $this->logger->error(
            'Centraliza check quota could not be read or claimed',
            ['exception_class' => $throwable::class, 'origin' => $throwable->getFile() . ':' . $throwable->getLine()]
        );

        return $this->unreachable();
    }

    /**
     * @return ConnectionResultInterface
     */
    private function unreachable(): ConnectionResultInterface
    {
        return $this->failure((string) __('Could not reach Centraliza right now. Try again in a moment.'));
    }

    /**
     * @param string $message
     * @param bool $rejected
     * @return ConnectionResultInterface
     */
    private function failure(string $message, bool $rejected = false): ConnectionResultInterface
    {
        // The caller is a screen, and a screen needs an answer, not an exception.
        return $this->resultFactory->create([
            'valid' => false,
            'errorMessage' => $message,
            'rejected' => $rejected,
        ]);
    }

    /**
     * @param int $waitSeconds
     * @return ConnectionResultInterface
     */
    private function throttled(int $waitSeconds): ConnectionResultInterface
    {
        return $this->resultFactory->create([
            'valid' => false,
            'errorMessage' => (string) __('Wait %1 seconds before checking the key again.', max(1, $waitSeconds)),
            'throttled' => true,
        ]);
    }
}
