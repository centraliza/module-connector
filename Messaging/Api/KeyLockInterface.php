<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api;

interface KeyLockInterface
{
    /**
     * Take the lock without waiting.
     *
     * @param string $lockName
     * @return bool false when another process holds it or the lock backend failed (logged)
     */
    public function acquire(string $lockName): bool;

    /**
     * Release the lock; a failure is logged, never thrown.
     *
     * @param string $lockName
     * @return void
     */
    public function release(string $lockName): void;
}
