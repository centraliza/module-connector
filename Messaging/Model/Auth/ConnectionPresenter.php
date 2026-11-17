<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Auth;

use Centraliza\Connector\Messaging\Api\ConfigInterface;
use DateTime;
use DateTimeZone;
use IntlDateFormatter;
use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The connection state is rendered twice — once by a block on page load, once
 * by JavaScript right after a click — and those are two different processes.
 * Letting each one derive its own message and colour is exactly how they drift:
 * a click used to show a plain, colourless label while a reload of the same
 * state showed the workspace name, the timestamp and green. This class is the
 * single place that maps a state to the pair the UI needs, so both renderers
 * read the same answer instead of computing two.
 */
class ConnectionPresenter
{
    private const COLOR_CONNECTED = '#006400';
    private const COLOR_PENDING = '#eb5202';
    private const COLOR_FAILED = '#e22626';

    /**
     * @param ConfigInterface $config
     * @param TimezoneInterface $timezone
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * Resolves the persisted state for the scope: no key, saved but unverified,
     * changed since the last check, or connected — in that order.
     *
     * @param int|null $websiteId
     * @return array{message: Phrase, color: string|null}
     */
    public function forScope(?int $websiteId): array
    {
        $apiKey = $this->config->getApiKey($websiteId);
        if ($apiKey === null) {
            return $this->pair(__('No API key configured.'), null);
        }

        $rejectedAt = $this->config->getRejectedAt($websiteId);
        if ($rejectedAt !== null) {
            return $this->pair(
                __(
                    'Centraliza rejected the API key on %1. Create a new key in the panel and authenticate again.',
                    $this->formatTimestamp($rejectedAt, $websiteId)
                ),
                self::COLOR_FAILED
            );
        }

        $validatedAt = $this->config->getValidatedAt($websiteId);
        if ($validatedAt === null) {
            return $this->pair(
                __('API key saved but not verified yet. Click Authenticate to check it.'),
                self::COLOR_PENDING
            );
        }

        $displayedValidatedAt = $this->formatTimestamp($validatedAt, $websiteId);

        if (!$this->config->isConnected($websiteId)) {
            return $this->pair(
                __(
                    'The API key changed after the last check on %1. Click Authenticate to verify it.',
                    $displayedValidatedAt
                ),
                self::COLOR_PENDING
            );
        }

        return $this->pair(
            __('Connected to %1. Verified at %2.', $this->config->getWorkspaceName($websiteId), $displayedValidatedAt),
            self::COLOR_CONNECTED
        );
    }

    /**
     * The state that exists only right after a rejected validation. Nothing
     * persists it: a reload always falls back to whatever forScope() resolves.
     *
     * @param string $reason
     * @return array{message: Phrase, color: string|null}
     */
    public function forFailure(string $reason): array
    {
        return $this->pair(new Phrase($reason), self::COLOR_FAILED);
    }

    /**
     * The last known state, flagged as not confirmed because Centraliza could not be reached.
     *
     * @param int|null $websiteId
     * @return array{message: Phrase, color: string|null}
     */
    public function forUnavailable(?int $websiteId): array
    {
        $lastKnown = $this->forScope($websiteId);

        return $this->pair(
            __('Could not confirm the connection right now. Last known state: %1', $lastKnown['message']),
            self::COLOR_PENDING
        );
    }

    /**
     * @param Phrase $message
     * @param string|null $color
     * @return array{message: Phrase, color: string|null}
     */
    private function pair(Phrase $message, ?string $color): array
    {
        return ['message' => $message, 'color' => $color];
    }

    /**
     * Stored as UTC ISO 8601 so it compares, sorts and survives a timezone change
     * without rewriting anything — never changed here. Displayed converted to the
     * admin's configured timezone and locale because an administrator reads a
     * wall clock, not an instant.
     *
     * DateTime built from a "...Z" string reports its own timezone name as "Z",
     * which TimezoneInterface::formatDateTime() does not recognise as UTC, so it
     * renders the raw stored hour instead of converting it. Explicitly setting
     * the target timezone here, before formatting, is what makes the conversion
     * actually happen.
     *
     * @param string $isoUtc
     * @param int|null $websiteId
     * @return string
     */
    private function formatTimestamp(string $isoUtc, ?int $websiteId): string
    {
        // Same scope discipline ScopeResolver enforces for reads/writes: a website
        // configured with a different timezone than default must convert against
        // its own, not the default scope's.
        $configTimezone = $websiteId === null
            ? $this->timezone->getConfigTimezone()
            : $this->timezone->getConfigTimezone(ScopeInterface::SCOPE_WEBSITE, $websiteId);

        $date = new DateTime($isoUtc, new DateTimeZone('UTC'));
        $date->setTimezone(new DateTimeZone($configTimezone));

        return $this->timezone->formatDateTime($date, IntlDateFormatter::MEDIUM, IntlDateFormatter::MEDIUM);
    }
}
