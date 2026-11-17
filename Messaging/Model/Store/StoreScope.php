<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Store;

use Centraliza\Connector\Messaging\Api\StoreScopeInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

class StoreScope implements StoreScopeInterface
{
    /**
     * Id of the admin website and of the admin store view.
     */
    private const ADMIN_ID = 0;

    /**
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return int[]
     */
    public function getWebsiteIds(): array
    {
        // Keyed by website id, and without the admin website unless asked for.
        return array_map('intval', array_keys($this->storeManager->getWebsites()));
    }

    /**
     * @param int $websiteId
     * @return bool
     */
    public function websiteExists(int $websiteId): bool
    {
        if ($websiteId === self::ADMIN_ID) {
            return false;
        }

        try {
            $this->storeManager->getWebsite($websiteId);
        } catch (LocalizedException) {
            return false;
        }

        return true;
    }

    /**
     * @param int $websiteId
     * @return int[]
     */
    public function getActiveStoreIds(int $websiteId): array
    {
        if ($websiteId === self::ADMIN_ID) {
            return [];
        }

        try {
            $storeIds = $this->storeManager->getWebsite($websiteId)->getStoreIds();
        } catch (LocalizedException) {
            return [];
        }

        $activeStoreIds = [];
        foreach ($storeIds as $storeId) {
            if (!empty($this->getActiveStore((int) $storeId))) {
                $activeStoreIds[] = (int) $storeId;
            }
        }

        return $activeStoreIds;
    }

    /**
     * @param int $storeId
     * @return string|null
     */
    public function getOrigin(int $storeId): ?string
    {
        // The admin store view is always enabled but has no storefront: nothing a customer
        // came from can point there.
        if ($storeId === self::ADMIN_ID) {
            return null;
        }

        $store = $this->getActiveStore($storeId);
        if (!$store instanceof Store) {
            return null;
        }

        $baseUrl = rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB, true), '/');
        if (empty($baseUrl)) {
            return null;
        }

        return $baseUrl;
    }

    /**
     * @param int $storeId
     * @return StoreInterface|null
     */
    private function getActiveStore(int $storeId): ?StoreInterface
    {
        try {
            $store = $this->storeManager->getStore($storeId);
        } catch (LocalizedException) {
            return null;
        }

        if (!$store->getIsActive()) {
            return null;
        }

        return $store;
    }
}
