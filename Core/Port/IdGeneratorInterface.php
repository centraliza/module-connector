<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Port;

interface IdGeneratorInterface
{
    public function next(): string;
}
