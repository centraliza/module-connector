<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api;

/**
 * Store view and website lookups every Centraliza module needs, resolved one way. No method
 * throws for an id that does not resolve: a caller with no store context (a cron, a consumer,
 * a request parameter) gets a plain "absent" answer instead. The admin website and store view
 * (id 0) count as absent, because no customer and no scoped setting belongs to them.
 */
interface StoreScopeInterface
{
    /**
     * Ids of every website of the installation, the admin website left out.
     *
     * @return int[]
     */
    public function getWebsiteIds(): array;

    /**
     * @param int $websiteId
     * @return bool
     */
    public function websiteExists(int $websiteId): bool;

    /**
     * Ids of the website's enabled store views; empty when the website does not resolve.
     *
     * @param int $websiteId
     * @return int[]
     */
    public function getActiveStoreIds(int $websiteId): array;

    /**
     * Secure base URL of an enabled store view without the trailing slash, or null.
     *
     * @param int $storeId
     * @return string|null
     */
    public function getOrigin(int $storeId): ?string;
}
