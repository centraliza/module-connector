<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model;

use Centraliza\Connector\Infra\Config\Settings;
use Centraliza\Connector\Messaging\Api\ConfigInterface;
use Centraliza\Connector\Messaging\Model\Auth\RejectedKeyStore;
use Magento\Framework\App\Config\ScopeConfigInterface;
use SensitiveParameter;

/**
 * The legacy Centraliza_Base configuration, read from this module's connection: one API key per environment
 * (production or staging) at default scope, where the legacy modules kept one per website. The website
 * argument of the interface only matters to isEnabled() and isReady().
 */
class Config implements ConfigInterface
{
    public const PATH_DEBUG = 'centraliza_connector/general/debug';

    /**
     * The validation state of each environment's key: these paths end in _production or _staging, so switching
     * the environment never shows the other key as connected.
     */
    public const PATH_CONNECTION_KEY_HASH = 'centraliza_connector/connection/key_hash_';
    public const PATH_CONNECTION_WORKSPACE_NAME = 'centraliza_connector/connection/workspace_name_';
    public const PATH_CONNECTION_VALIDATED_AT = 'centraliza_connector/connection/validated_at_';

    /**
     * Keyed by API key hash, so one map serves both environments.
     */
    public const PATH_CONNECTION_REJECTED_KEYS = 'centraliza_connector/connection/rejected_keys';

    /**
     * Relative paths resolve under it: the trailing slash keeps /v1.
     */
    private const API_VERSION_PATH = '/v1/';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Settings $settings
     * @param RejectedKeyStore $rejectedKeyStore
     * @param LegacyModules $legacyModules
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Settings $settings,
        private readonly RejectedKeyStore $rejectedKeyStore,
        private readonly LegacyModules $legacyModules
    ) {
    }

    /**
     * On when the website has a store view set to "Report changes to Centraliza"; null asks for any website.
     *
     * @param int|null $websiteId
     * @return bool
     */
    public function isEnabled(?int $websiteId = null): bool
    {
        $enabledWebsiteIds = $this->settings->enabledWebsiteIds();
        if ($websiteId === null) {
            return $enabledWebsiteIds !== [];
        }

        return in_array($websiteId, $enabledWebsiteIds, true);
    }

    /**
     * The key of the selected environment; the same for every website.
     *
     * @param int|null $websiteId
     * @return string|null
     */
    public function getApiKey(?int $websiteId = null): ?string
    {
        $apiKey = $this->settings->apiKey();

        return $apiKey === '' ? null : $apiKey;
    }

    /**
     * @return string
     */
    public function getApiBaseUrl(): string
    {
        return $this->settings->baseUrl() . self::API_VERSION_PATH;
    }

    /**
     * @return bool
     */
    public function isDebugEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH_DEBUG);
    }

    /**
     * Recomputed from the configured key against the validated one; a refused key is not connected.
     *
     * @param int|null $websiteId
     * @return bool
     */
    public function isConnected(?int $websiteId = null): bool
    {
        // Cheapest check first: an unvalidated environment exits here.
        $storedApiKeyHash = $this->getValue(self::PATH_CONNECTION_KEY_HASH);
        if ($storedApiKeyHash === null) {
            return false;
        }

        $apiKey = $this->getApiKey();
        if ($apiKey === null) {
            return false;
        }

        $apiKeyHash = $this->hashApiKey($apiKey);
        if (!hash_equals($storedApiKeyHash, $apiKeyHash)) {
            return false;
        }

        return $this->rejectedKeyStore->getRejectedAt($apiKeyHash) === null;
    }

    /**
     * Off while the legacy Centraliza_Sales module is enabled: it still sends the same messages, and two
     * senders would message each customer twice.
     *
     * @param int|null $websiteId
     * @return bool
     */
    public function isReady(?int $websiteId = null): bool
    {
        return !$this->legacyModules->areActive() && $this->isEnabled($websiteId) && $this->isConnected($websiteId);
    }

    /**
     * Unsalted is enough: the key is high-entropy and the digest is never displayed. The connection events
     * hash the key the same way, so both share one quota row.
     *
     * @param string $apiKey
     * @return string
     */
    public function hashApiKey(#[SensitiveParameter] string $apiKey): string
    {
        return hash('sha256', $apiKey);
    }

    /**
     * @param int|null $websiteId
     * @return string|null
     */
    public function getWorkspaceName(?int $websiteId = null): ?string
    {
        return $this->getValue(self::PATH_CONNECTION_WORKSPACE_NAME);
    }

    /**
     * @param int|null $websiteId
     * @return string|null
     */
    public function getValidatedAt(?int $websiteId = null): ?string
    {
        return $this->getValue(self::PATH_CONNECTION_VALIDATED_AT);
    }

    /**
     * Null unless the current key was refused.
     *
     * @param int|null $websiteId
     * @return string|null
     */
    public function getRejectedAt(?int $websiteId = null): ?string
    {
        $apiKey = $this->getApiKey();
        if ($apiKey === null) {
            return null;
        }

        return $this->rejectedKeyStore->getRejectedAt($this->hashApiKey($apiKey));
    }

    /**
     * The path of a per-environment value (PATH_CONNECTION_*) for the selected environment.
     *
     * @param string $path
     * @return string
     */
    public function forEnvironment(string $path): string
    {
        return $path . $this->settings->environment();
    }

    /**
     * @param string $path one of the PATH_CONNECTION_* prefixes
     * @return string|null
     */
    private function getValue(string $path): ?string
    {
        $value = $this->scopeConfig->getValue($this->forEnvironment($path));

        // Not empty(): '0' is a real value (a workspace named "0").
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
