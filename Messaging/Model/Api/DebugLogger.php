<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Api;

use Centraliza\Connector\Messaging\Api\ConfigInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Trace logger for routine lines (what a run selected, why an item was skipped). It writes only
 * while the Debug Log setting (centraliza/general/debug) is on, the same switch as the Base's
 * request trace; warnings and errors belong in the regular Centraliza logger, which always writes.
 */
class DebugLogger implements LoggerInterface
{
    use LoggerTrait;

    /**
     * @param LoggerInterface $logger
     * @param ConfigInterface $config
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ConfigInterface $config
    ) {
    }

    /**
     * @param mixed $level
     * @param string|Stringable $message
     * @param array $context
     * @return void
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!$this->config->isDebugEnabled()) {
            return;
        }

        // Written at info whatever level was asked: the Centraliza log handler drops anything
        // below info, and a trace line switched on in the admin must reach the file.
        $this->logger->log(LogLevel::INFO, $message, $context);
    }
}
