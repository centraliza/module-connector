<?php

declare(strict_types=1);

namespace Centraliza\Connector\Backfill;

/**
 * Every record of one stream that the reporting website covers, in pages, for a send of the whole stream.
 */
interface BackfillSourceInterface
{
    /**
     * How many records a send of the whole stream puts on the queue.
     */
    public function count(int $websiteId): int;

    /**
     * The next page of record keys after $lastKey ('' for the first page).
     *
     * @return array{0: list<array<string, scalar>>, 1: string} the keys, and what to pass as $lastKey next
     */
    public function next(int $websiteId, string $lastKey, int $limit): array;
}
