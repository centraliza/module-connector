<?php

declare(strict_types=1);

namespace Centraliza\Connector\Infra\Queue;

use Centraliza\Connector\Core\Model\Action;
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
            $held = $this->codec->decode($message);
            if ($this->deliverEvent->deliver($held, mt_rand() / mt_getrandmax()) === Action::Quarantine) {
                $this->logger->warning('Centraliza Connector quarantined an event.', [
                    'stream' => $held->event->stream,
                    'verb' => $held->event->verb->value,
                    'key' => $held->event->key,
                ]);
            }
        } catch (Throwable $e) {
            $this->logger->error('Centraliza Connector could not handle a queue message.', ['exception' => $e]);
        }
    }
}
