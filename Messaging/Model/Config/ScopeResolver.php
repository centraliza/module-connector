<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Config;

use Centraliza\Connector\Messaging\Api\ScopeResolverInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The one place that knows a config read takes "website" and a write takes "websites": a wrong one fails silently.
 */
class ScopeResolver implements ScopeResolverInterface
{
    /**
     * @param int|null $websiteId
     * @return array
     */
    public function forRead(?int $websiteId): array
    {
        if ($websiteId === null) {
            return [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null];
        }

        return [ScopeInterface::SCOPE_WEBSITE, $websiteId];
    }

    /**
     * @param int|null $websiteId
     * @return array
     */
    public function forWrite(?int $websiteId): array
    {
        if ($websiteId === null) {
            return [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0];
        }

        return [ScopeInterface::SCOPE_WEBSITES, $websiteId];
    }

    /**
     * @param mixed $websiteParam
     * @return int|null
     */
    public function getWebsiteIdFromParam(mixed $websiteParam): ?int
    {
        // Not empty(): website '0' is a real id; anything but a non-negative integer is no website.
        $websiteId = filter_var($websiteParam, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($websiteId === false) {
            return null;
        }

        return $websiteId;
    }
}
