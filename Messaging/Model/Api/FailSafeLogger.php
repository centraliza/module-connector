<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Api;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;
use Throwable;

/**
 * Logger for the Centraliza HTTP client whose failures never reach the request.
 *
 * The Guzzle log middleware writes after the response has arrived. A broken log stream
 * raised there would replace the response with a raw exception: a message Centraliza
 * already accepted would look failed, and a consumer could not tell it had been sent.
 */
class FailSafeLogger implements LoggerInterface
{
    use LoggerTrait;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly LoggerInterface $logger
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
        try {
            $this->logger->log($level, $message, $context);
        } catch (Throwable $exception) {
            $this->reportFailure($exception);
        }
    }

    /**
     * @param Throwable $exception
     * @return void
     */
    private function reportFailure(Throwable $exception): void
    {
        // Only the class is reported: the message of a failed write can quote the entry
        // it was writing. The report goes to the wrapped logger, never back through this
        // class, so a logger that keeps failing cannot recurse. If it fails again there is
        // no channel left, and giving up is preferable to losing the Centraliza response.
        try {
            $this->logger->warning(
                'Centraliza could not write a log entry',
                ['exception_class' => $exception::class]
            );
        } catch (Throwable) {
            return;
        }
    }
}
