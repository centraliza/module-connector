<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Model;

final readonly class HeldEvent
{
    public function __construct(
        public ConnectionEvent $event,
        public string $lane,
        public int $attempts
    ) {
    }
}
