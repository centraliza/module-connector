<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Port;

/**
 * Reads the record an event is about, so the event can carry it.
 */
interface RecordReaderInterface
{
    /**
     * False for a stream whose events carry only the key.
     */
    public function supports(string $stream): bool;

    /**
     * The record as it is now, with every field the store knows about it.
     *
     * @param array<string, scalar> $key
     * @return array<string, mixed>|null null when the record no longer exists
     * @throws \Throwable when the record exists but could not be read
     */
    public function read(string $stream, array $key): ?array;
}
