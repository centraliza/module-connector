<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Auth;

use Centraliza\Connector\Messaging\Model\Config;
use InvalidArgumentException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * A refusal is a fact about a key, not about the environment it was checked from: the rejections live in one
 * map at default scope, keyed by API key hash, and this class is the only reader and writer of that map.
 */
class RejectedKeyStore
{
    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param WriterInterface $configWriter
     * @param Json $json
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param string $apiKeyHash
     * @return string|null
     */
    public function getRejectedAt(string $apiKeyHash): ?string
    {
        return $this->getAll()[$apiKeyHash] ?? null;
    }

    /**
     * @param string $apiKeyHash
     * @param string $rejectedAt
     * @return void
     */
    public function add(string $apiKeyHash, string $rejectedAt): void
    {
        $rejectedKeys = $this->getAll();
        $rejectedKeys[$apiKeyHash] = $rejectedAt;

        $this->write($rejectedKeys);
    }

    /**
     * @param string $apiKeyHash
     * @return void
     */
    public function remove(string $apiKeyHash): void
    {
        $rejectedKeys = $this->getAll();
        if (!isset($rejectedKeys[$apiKeyHash])) {
            return;
        }

        unset($rejectedKeys[$apiKeyHash]);
        $this->write($rejectedKeys);
    }

    /**
     * @return array<string, string>
     */
    private function getAll(): array
    {
        $value = $this->scopeConfig->getValue(
            Config::PATH_CONNECTION_REJECTED_KEYS,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );
        if (!is_string($value) || $value === '') {
            return [];
        }

        try {
            $decoded = $this->json->unserialize($value);
        } catch (InvalidArgumentException) {
            $decoded = null;
        }

        if (!is_array($decoded)) {
            // A corrupted value must not take the configuration screen or any isReady() caller
            // down with it; reading it as "nothing rejected" only costs one more API check.
            $this->logger->warning('Centraliza: unreadable rejected API key list, ignoring it.', [
                'path' => Config::PATH_CONNECTION_REJECTED_KEYS,
            ]);

            return [];
        }

        return array_filter(
            $decoded,
            static fn ($rejectedAt, $apiKeyHash): bool => is_string($apiKeyHash) && is_string($rejectedAt),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * @param array<string, string> $rejectedKeys
     * @return void
     */
    private function write(array $rejectedKeys): void
    {
        if ($rejectedKeys === []) {
            $this->configWriter->delete(
                Config::PATH_CONNECTION_REJECTED_KEYS,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                0
            );

            return;
        }

        $this->configWriter->save(
            Config::PATH_CONNECTION_REJECTED_KEYS,
            (string) $this->json->serialize($rejectedKeys),
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            0
        );
    }
}
