<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api;

use InvalidArgumentException;

interface QuotaReservationInterface
{
    /**
     * Interactive: a person waiting on the admin screen.
     */
    public const PRIORITY_INTERACTIVE = 'interactive';

    /**
     * Transactional: order notifications.
     */
    public const PRIORITY_TRANSACTIONAL = 'transactional';

    /**
     * Marketing: abandoned-cart reminders.
     */
    public const PRIORITY_MARKETING = 'marketing';

    /**
     * Bulk: connection events, which take what the messages leave.
     */
    public const PRIORITY_BULK = 'bulk';

    /**
     * Reserve one request of the key's current window.
     *
     * @param string $apiKeyHash
     * @param string $priority one of the PRIORITY_* constants
     * @param string $route relative API path of the request, e.g. admin/notification/send
     * @return bool false when the key is blocked, a refusal pauses the route, or the priority's floor was reached
     * @throws InvalidArgumentException for an unknown priority, or a route empty or over 255 characters
     */
    public function reserve(string $apiKeyHash, string $priority, string $route): bool;

    /**
     * Count requests already made without a reservation (automatic repetitions).
     *
     * @param string $apiKeyHash
     * @param int $requests
     * @return void
     */
    public function consume(string $apiKeyHash, int $requests): void;

    /**
     * Align the count with the remaining quota a Centraliza answer reported.
     *
     * @param string $apiKeyHash
     * @param int $remaining
     * @return void
     */
    public function recordServerRemaining(string $apiKeyHash, int $remaining): void;

    /**
     * Refuse every reservation of the key for the given time, in every process.
     *
     * @param string $apiKeyHash
     * @param int $seconds
     * @return void
     */
    public function block(string $apiKeyHash, int $seconds): void;

    /**
     * Pause after a refusal: a 401 pauses every route of the key but interactive calls, a 403 only the route.
     *
     * @param string $apiKeyHash
     * @param string $route relative API path of the refused call
     * @param int $httpStatus 401 or 403
     * @return void
     * @throws InvalidArgumentException for a route empty or over 255 characters
     */
    public function refuse(string $apiKeyHash, string $route, int $httpStatus): void;

    /**
     * After a 2xx, clear the route's refusal; an interactive 2xx clears only the key-wide (401) one.
     *
     * @param string $apiKeyHash
     * @param string $priority one of the PRIORITY_* constants, the priority of the accepted call
     * @param string $route relative API path of the accepted call
     * @return void
     * @throws InvalidArgumentException for a route empty or over 255 characters
     */
    public function clearRefusal(string $apiKeyHash, string $priority, string $route): void;

    /**
     * @param string $apiKeyHash
     * @param string $priority one of the PRIORITY_* constants
     * @return int requests left in the current window; 0 while the key is blocked or a 401 pauses the priority
     */
    public function getRemaining(string $apiKeyHash, string $priority): int;

    /**
     * @param string $apiKeyHash
     * @param string $priority one of the PRIORITY_* constants
     * @param string $route relative API path of the call
     * @return int seconds until the window, the block and the refusals pausing the route end, whichever is later
     * @throws InvalidArgumentException for a route empty or over 255 characters
     */
    public function getWaitSeconds(string $apiKeyHash, string $priority, string $route): int;

    /**
     * Stamp an Authenticate check unless one was stamped in the last 60 s, in every process.
     *
     * @param string $checkHash the key's hash, or any hash naming what is checked (e.g. a scope)
     * @return bool true when this process may check now
     */
    public function claimCheck(string $checkHash): bool;

    /**
     * @param string $checkHash the key's hash, or any hash naming what is checked (e.g. a scope)
     * @return int seconds until the next check may be claimed
     */
    public function getCheckWaitSeconds(string $checkHash): int;
}
