<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Config;

use Centraliza\Connector\Core\Port\SettingsInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Settings implements SettingsInterface
{
    private const XML_PATH_ENABLED = 'centraliza_connector/general/enabled';
    private const XML_PATH_MODE = 'centraliza_connector/general/mode';
    private const XML_PATH_ENVIRONMENT = 'centraliza_connector/general/environment';
    /** Each environment has its own key, channel and API host: the paths end in _production or _staging. */
    private const XML_PATH_CONNECTION_ID = 'centraliza_connector/general/connection_id_';
    private const XML_PATH_API_KEY = 'centraliza_connector/general/api_key_';
    private const XML_PATH_BASE_URL = 'centraliza_connector/general/base_url_';
    private const XML_PATH_GUEST_CARTS = 'centraliza_connector/carts/guests';
    private const XML_PATH_TELEMETRY_LOG = 'centraliza_connector/general/telemetry_log';
    private const XML_PATH_MAX_ATTEMPTS = 'centraliza_connector/delivery/max_attempts';

    private const DEFAULT_MAX_ATTEMPTS = 5;

    public const MODE_DRY_RUN = 'dry_run';
    public const MODE_LIVE = 'live';

    public const ENV_PRODUCTION = 'production';
    public const ENV_STAGING = 'staging';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabledWebsiteIds() !== [];
    }

    public function enabledWebsiteIds(): array
    {
        $websiteIds = [];
        foreach ($this->storeManager->getStores() as $store) {
            if ($this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $store->getId())) {
                $websiteIds[(int)$store->getWebsiteId()] = (int)$store->getWebsiteId();
            }
        }

        return array_values($websiteIds);
    }

    public function reportingStoreId(): ?int
    {
        foreach ($this->storeManager->getStores() as $store) {
            if ($this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $store->getId())) {
                return (int)$store->getId();
            }
        }

        return null;
    }

    /**
     * The host of the store view that reports, to tell stores apart in telemetry.
     */
    public function reportingHost(): string
    {
        $storeId = $this->reportingStoreId();
        if ($storeId === null) {
            return '';
        }

        return (string)parse_url((string)$this->scopeConfig->getValue('web/secure/base_url', ScopeInterface::SCOPE_STORE, $storeId), PHP_URL_HOST);
    }

    /**
     * Which Centraliza the store talks to: production (app.centraliza.ai) or staging (app.centraliza.dev).
     */
    public function environment(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_ENVIRONMENT) === self::ENV_STAGING
            ? self::ENV_STAGING
            : self::ENV_PRODUCTION;
    }

    /**
     * The channel (connection) id of the selected environment.
     */
    public function connectionId(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_PATH_CONNECTION_ID . $this->environment()));
    }

    /**
     * Whether telemetry is also written to a log file. No admin field: set it where it is needed.
     */
    public function logsTelemetry(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_TELEMETRY_LOG);
    }

    public function maxAttempts(): int
    {
        $configured = (int)$this->scopeConfig->getValue(self::XML_PATH_MAX_ATTEMPTS);

        return $configured >= 1 ? $configured : self::DEFAULT_MAX_ATTEMPTS;
    }

    public function reportsGuestCarts(int $websiteId): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_GUEST_CARTS, ScopeInterface::SCOPE_WEBSITE, $websiteId);
    }

    /**
     * False while requests are only written to the dry-run log.
     */
    public function isLive(): bool
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_MODE) === self::MODE_LIVE;
    }

    /**
     * The workspace key (csk_...) of the selected environment, decrypted; empty when not configured.
     */
    public function apiKey(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_PATH_API_KEY . $this->environment()));
    }

    public function baseUrl(): string
    {
        return rtrim(trim((string)$this->scopeConfig->getValue(self::XML_PATH_BASE_URL . $this->environment())), '/');
    }
}
