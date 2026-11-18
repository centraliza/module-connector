<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api;

use Centraliza\Connector\Messaging\Exception\CentralizaApiException;

/**
 * Sends reserved notifications to Centraliza and records what happened to each one.
 */
interface SendNotificationServiceInterface
{
    /**
     * Send one key's rows that are ready to send, its websites taking turns, and record the outcome of each.
     *
     * @param int[] $websiteIds websites sending with the same key, in serving order
     * @return int requests made, counted against the key's quota
     * @throws CentralizaApiException when Centraliza is down or unreachable: the whole round ends
     */
    public function sendReadyRows(array $websiteIds): int;

    /**
     * Fail, without a request, the waiting rows of a website the round skips that are past the window.
     *
     * @param int $websiteId
     * @return int rows failed
     */
    public function failRowsPastSendWindow(int $websiteId): int;

    /**
     * Whether a notification created at the given time is too old to be sent on the website.
     *
     * @param string $createdAt GMT, in the format of the created_at column
     * @param int $websiteId
     * @return bool
     */
    public function isPastSendWindow(string $createdAt, int $websiteId): bool;
}
