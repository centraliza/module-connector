<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api;

interface ScopeResolverInterface
{
    /**
     * Scope/scope-id pair for a config read.
     *
     * @param int|null $websiteId
     * @return array
     */
    public function forRead(?int $websiteId): array;

    /**
     * Scope/scope-id pair for a config write.
     *
     * @param int|null $websiteId
     * @return array
     */
    public function forWrite(?int $websiteId): array;

    /**
     * Website id held by an admin request's "website" parameter; null (default scope) unless a non-negative integer.
     *
     * @param mixed $websiteParam the raw request value
     * @return int|null
     */
    public function getWebsiteIdFromParam(mixed $websiteParam): ?int;
}
