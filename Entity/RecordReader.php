<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity;

use Centraliza\Connector\Core\Port\RecordReaderInterface;

/**
 * Hands each stream to its reader. Filled from di.xml: a stream with no
 * reader is reported with its key only.
 */
class RecordReader implements RecordReaderInterface
{
    /**
     * @param array<string, StreamReaderInterface> $readers by stream
     */
    public function __construct(
        private readonly array $readers = []
    ) {
    }

    public function supports(string $stream): bool
    {
        return isset($this->readers[$stream]);
    }

    public function read(string $stream, array $key): ?array
    {
        return $this->readers[$stream]->read($key);
    }
}
