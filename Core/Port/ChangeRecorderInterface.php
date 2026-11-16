<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Port;

use Centraliza\Connector\Core\Model\EventVerb;

/**
 * The only thing a capture hook calls.
 */
interface ChangeRecorderInterface
{
    /**
     * Notes that a record changed. Never throws: a hook must not break the save it observes.
     *
     * @param array<string, scalar> $key the record's key fields for this stream
     */
    public function record(string $stream, EventVerb $verb, array $key): void;
}
