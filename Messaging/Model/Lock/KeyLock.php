<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Lock;

use Centraliza\Connector\Messaging\Api\KeyLockInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class KeyLock implements KeyLockInterface
{
    /**
     * @param LockManagerInterface $lockManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param string $lockName
     * @return bool
     */
    public function acquire(string $lockName): bool
    {
        try {
            return $this->lockManager->lock($lockName, 0);
        } catch (Throwable $exception) {
            // The caller skips this key for the run; the other keys still send.
            $this->logger->error('Centraliza key lock could not be taken', [
                'lock' => $lockName,
                'exception_class' => $exception::class,
                'origin' => $exception->getFile() . ':' . $exception->getLine(),
            ]);

            return false;
        }
    }

    /**
     * @param string $lockName
     * @return void
     */
    public function release(string $lockName): void
    {
        try {
            $this->lockManager->unlock($lockName);
        } catch (Throwable $exception) {
            // Not rethrown: the lock is released anyway when the database session closes.
            $this->logger->warning('Centraliza key lock could not be released', [
                'lock' => $lockName,
                'exception_class' => $exception::class,
            ]);
        }
    }
}
