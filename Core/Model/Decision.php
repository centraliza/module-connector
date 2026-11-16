<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Model;

final readonly class Decision
{
    public function __construct(
        public Action $action,
        public int $delaySeconds = 0
    ) {
    }
}
