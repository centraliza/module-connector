<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Port;

interface SettingsInterface
{
    /**
     * True when at least one store view reports to Centraliza.
     */
    public function isEnabled(): bool;

    /**
     * Websites that have at least one store view reporting to Centraliza.
     *
     * @return int[]
     */
    public function enabledWebsiteIds(): array;

    /**
     * The id of the Magento connection in Centraliza (Settings > Channels); empty when not configured.
     */
    public function connectionId(): string;

    /**
     * How many failed sends one event may have. The failure that reaches this
     * number sends the event to quarantine. Never less than 1.
     */
    public function maxAttempts(): int;

    /**
     * Whether carts with no customer behind them are reported for this website.
     */
    public function reportsGuestCarts(int $websiteId): bool;
}
