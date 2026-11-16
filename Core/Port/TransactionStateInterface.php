<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Port;

interface TransactionStateInterface
{
    public function inTransaction(): bool;
}
