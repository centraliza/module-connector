<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\OrderNotification;

/**
 * How many of the notifications an administrator chose were scheduled for resend, and why the others were not.
 */
final class ResendSummary
{
    /**
     * @param int $resent
     * @param int $notResendable rows in a state other than failed or not_sent
     * @param int $expired rows created before the website's send window
     * @param int $permanentError failed rows whose last error a resend cannot fix
     */
    public function __construct(
        private readonly int $resent,
        private readonly int $notResendable,
        private readonly int $expired,
        private readonly int $permanentError
    ) {
    }

    /**
     * @return int
     */
    public function getResent(): int
    {
        return $this->resent;
    }

    /**
     * @return int
     */
    public function getNotResendable(): int
    {
        return $this->notResendable;
    }

    /**
     * @return int
     */
    public function getExpired(): int
    {
        return $this->expired;
    }

    /**
     * @return int
     */
    public function getPermanentError(): int
    {
        return $this->permanentError;
    }
}
