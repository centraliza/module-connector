<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api;

interface ConfigInterface
{
    /**
     * Whether the Centraliza integration is turned on for the given scope.
     *
     * A website id that does not resolve returns false rather than throwing.
     *
     * @param int|null $websiteId
     * @return bool
     */
    public function isEnabled(?int $websiteId = null): bool;

    /**
     * Decrypted API key for the given scope.
     *
     * A website id that does not resolve returns null rather than throwing.
     *
     * @param int|null $websiteId
     * @return string|null
     */
    public function getApiKey(?int $websiteId = null): ?string;

    /**
     * Base URL of the Centraliza API of the website's environment, always with a trailing slash.
     *
     * @param int|null $websiteId
     * @return string
     */
    public function getApiBaseUrl(?int $websiteId = null): string;

    /**
     * Whether every request to Centraliza is traced in the module's log file.
     *
     * @return bool
     */
    public function isDebugEnabled(): bool;

    /**
     * Derived state: true only when a validation is stored for the key currently
     * configured in this scope. A website id that does not resolve returns false
     * rather than throwing.
     *
     * @param int|null $websiteId
     * @return bool
     */
    public function isConnected(?int $websiteId = null): bool;

    /**
     * The single question a consuming module asks before calling the platform:
     * turned on and holding a validated key for this scope. Callers gate on this
     * instead of combining the two checks themselves, so a future condition is
     * added here once rather than in every module.
     *
     * Derived from the last stored validation, never a live check: a key revoked
     * on the platform side still reads true until a real call answers 401.
     *
     * @param int|null $websiteId
     * @return bool
     */
    public function isReady(?int $websiteId = null): bool;

    /**
     * A website id that does not resolve returns null rather than throwing.
     *
     * @param int|null $websiteId
     * @return string|null
     */
    public function getWorkspaceName(?int $websiteId = null): ?string;

    /**
     * A website id that does not resolve returns null rather than throwing.
     *
     * @param int|null $websiteId
     * @return string|null
     */
    public function getValidatedAt(?int $websiteId = null): ?string;

    /**
     * UTC ISO 8601 timestamp of the last automatic check that Centraliza refused, or null.
     *
     * @param int|null $websiteId
     * @return string|null
     */
    public function getRejectedAt(?int $websiteId = null): ?string;

    /**
     * SHA-256 hash of an API key: compares a configured key with a validated one and names the key's quota row.
     *
     * @param string $apiKey
     * @return string
     */
    public function hashApiKey(string $apiKey): string;
}
