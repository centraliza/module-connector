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
    private const XML_PATH_CONNECTION_ID = 'centraliza_connector/general/connection_id';
    private const XML_PATH_MODE = 'centraliza_connector/general/mode';
    private const XML_PATH_API_KEY = 'centraliza_connector/general/api_key';
    private const XML_PATH_BASE_URL = 'centraliza_connector/general/base_url';
    private const XML_PATH_GUEST_CARTS = 'centraliza_connector/carts/guests';
    private const XML_PATH_MAX_ATTEMPTS = 'centraliza_connector/delivery/max_attempts';

    private const DEFAULT_MAX_ATTEMPTS = 5;

    public const MODE_DRY_RUN = 'dry_run';
    public const MODE_LIVE = 'live';

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

    public function connectionId(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_PATH_CONNECTION_ID));
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
     * The workspace key (csk_...), decrypted; empty when not configured.
     */
    public function apiKey(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_PATH_API_KEY));
    }

    public function baseUrl(): string
    {
        return rtrim(trim((string)$this->scopeConfig->getValue(self::XML_PATH_BASE_URL)), '/');
    }
}
