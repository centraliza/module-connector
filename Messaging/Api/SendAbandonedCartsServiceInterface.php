<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api;

use Centraliza\Connector\Messaging\Exception\CentralizaApiException;

interface SendAbandonedCartsServiceInterface
{
    /**
     * Send the rows ready to send of websites sharing one Centraliza API key.
     *
     * @param int[] $websiteIds
     * @return int requests made, counted against the key's quota
     * @throws CentralizaApiException when Centraliza is down or unreachable: the whole run ends
     */
    public function send(array $websiteIds): int;

    /**
     * Fail the reservations left open past the timeout; they are never resent.
     *
     * @return int how many rows were failed
     */
    public function releaseExpiredReservations(): int;
}
