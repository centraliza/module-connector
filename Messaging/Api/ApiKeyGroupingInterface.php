<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api;

/**
 * The quota and the credential belong to the key: websites inheriting one key share them, so sends are planned per key.
 */
interface ApiKeyGroupingInterface
{
    /**
     * Ready websites grouped by API key hash; websites that are not ready or cannot be read are left out.
     *
     * @param int[] $websiteIds
     * @return array<string, int[]>
     */
    public function groupWebsitesByApiKey(array $websiteIds): array;
}
