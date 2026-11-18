<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\CartRedirect;

use Centraliza\Connector\Messaging\Api\ScopeResolverInterface;
use Centraliza\Connector\Messaging\Api\CartRedirectConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Config implements CartRedirectConfigInterface
{
    /**
     * Group of the Centraliza Connector section, per website, as in Centraliza_Sales (centraliza/cart_redirect/enabled).
     */
    private const PATH_ENABLED = 'centraliza_connector/cart_redirect/enabled';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param ScopeResolverInterface $scopeResolver
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ScopeResolverInterface $scopeResolver
    ) {
    }

    /**
     * @param int|null $websiteId
     * @return bool
     */
    public function isEnabled(?int $websiteId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH_ENABLED, ...$this->scopeResolver->forRead($websiteId));
    }
}
