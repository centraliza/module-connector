<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Queue;

use Centraliza\Connector\Core\Service\DeliverEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queue handler. It never throws: the framework consumer rejects a message
 * whose handler throws, without retrying it.
 */
class Consumer
{
    public function __construct(
        private readonly MessageCodec $codec,
        private readonly DeliverEvent $deliverEvent,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(string $message): void
    {
        try {
            $this->deliverEvent->deliver($this->codec->decode($message), mt_rand() / mt_getrandmax());
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not handle a queue message.', ['exception' => $e]);
        }
    }
}
