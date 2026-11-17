<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Config;

use Centraliza\Connector\Core\Port\SettingsInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Every setting is per website, with Default Config as what a website inherits.
 *
 * One website reports to Centraliza at a time (centraliza_connector/general/enabled on that website), and the
 * module reads every other setting at that website's scope: environment, key, channel, mode, attempts, limits.
 * The admin refuses turning a second website on; if one is turned on anyway (CLI, SQL), the lowest website id wins.
 */
class Settings implements SettingsInterface
{
    public const XML_PATH_ENABLED = 'centraliza_connector/general/enabled';
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
        return $this->reportingWebsiteId() !== null;
    }

    /**
     * Websites turned on, lowest id first, whatever the one-at-a-time rule says.
     *
     * @return int[]
     */
    public function websitesTurnedOn(): array
    {
        $websiteIds = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $websiteId = (int)$website->getId();
            if ($this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_WEBSITE, $websiteId)) {
                $websiteIds[] = $websiteId;
            }
        }
        sort($websiteIds);

        return $websiteIds;
    }

    /**
     * The website that reports to Centraliza, or null when none does.
     */
    public function reportingWebsiteId(): ?int
    {
        return $this->websitesTurnedOn()[0] ?? null;
    }

    public function enabledWebsiteIds(): array
    {
        $websiteId = $this->reportingWebsiteId();

        return $websiteId === null ? [] : [$websiteId];
    }

    /**
     * The default store view of the reporting website.
     */
    public function reportingStoreId(): ?int
    {
        $websiteId = $this->reportingWebsiteId();
        if ($websiteId === null) {
            return null;
        }
        $groupId = (int)$this->storeManager->getWebsite($websiteId)->getDefaultGroupId();
        $storeId = $groupId > 0 ? (int)$this->storeManager->getGroup((string)$groupId)->getDefaultStoreId() : 0;

        return $storeId > 0 ? $storeId : null;
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
     * A setting of this module at a website's scope: the reporting website when none is given, Default Config
     * when no website reports.
     */
    public function get(string $path, ?int $websiteId = null): mixed
    {
        $websiteId ??= $this->reportingWebsiteId();
        if ($websiteId === null) {
            return $this->scopeConfig->getValue($path);
        }

        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_WEBSITE, $websiteId);
    }

    /**
     * Which Centraliza the website talks to: production (app.centraliza.ai) or staging (app.centraliza.dev).
     */
    public function environment(?int $websiteId = null): string
    {
        return (string)$this->get(self::XML_PATH_ENVIRONMENT, $websiteId) === self::ENV_STAGING
            ? self::ENV_STAGING
            : self::ENV_PRODUCTION;
    }

    /**
     * The channel (connection) id of the website's environment.
     */
    public function connectionId(?int $websiteId = null): string
    {
        return trim((string)$this->get(self::XML_PATH_CONNECTION_ID . $this->environment($websiteId), $websiteId));
    }

    /**
     * Whether telemetry is also written to a log file. No admin field: set it where it is needed.
     */
    public function logsTelemetry(): bool
    {
        return (bool)$this->get(self::XML_PATH_TELEMETRY_LOG);
    }

    public function maxAttempts(): int
    {
        $configured = (int)$this->get(self::XML_PATH_MAX_ATTEMPTS);

        return $configured >= 1 ? $configured : self::DEFAULT_MAX_ATTEMPTS;
    }

    public function reportsGuestCarts(int $websiteId): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_GUEST_CARTS, ScopeInterface::SCOPE_WEBSITE, $websiteId);
    }

    /**
     * False while requests are only written to the dry-run log.
     */
    public function isLive(?int $websiteId = null): bool
    {
        return (string)$this->get(self::XML_PATH_MODE, $websiteId) === self::MODE_LIVE;
    }

    /**
     * The workspace key (csk_...) of the website's environment, decrypted; empty when not configured.
     */
    public function apiKey(?int $websiteId = null): string
    {
        return trim((string)$this->get($this->apiKeyPath($websiteId), $websiteId));
    }

    /**
     * Where the key of the website's environment is stored (encrypted).
     */
    public function apiKeyPath(?int $websiteId = null): string
    {
        return self::XML_PATH_API_KEY . $this->environment($websiteId);
    }

    public function baseUrl(?int $websiteId = null): string
    {
        return rtrim(trim((string)$this->get(self::XML_PATH_BASE_URL . $this->environment($websiteId), $websiteId)), '/');
    }
}
