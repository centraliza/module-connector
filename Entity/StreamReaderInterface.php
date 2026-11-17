<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity;

/**
 * Reads the records of one stream.
 */
interface StreamReaderInterface
{
    /**
     * @param array<string, scalar> $key the key fields the stream declares
     * @return array<string, mixed>|null null when the record no longer exists
     */
    public function read(array $key): ?array;
}
