<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Service;

use Centraliza\Connector\Core\Model\EventVerb;

/**
 * Tells what an operation did to a set of records from how they were stored
 * before and after it. Used where the operation writes with direct SQL and
 * hands over nothing to compare.
 */
final class SnapshotDiff
{
    /**
     * @param array<string, mixed> $before stored state by record id
     * @param array<string, mixed> $after
     * @return array<string, EventVerb> only the records that changed
     */
    public function verbs(array $before, array $after): array
    {
        $verbs = [];
        foreach ($after as $id => $state) {
            if (!array_key_exists($id, $before)) {
                $verbs[(string)$id] = EventVerb::Created;
            } elseif ($before[$id] !== $state) {
                $verbs[(string)$id] = EventVerb::Updated;
            }
        }
        foreach (array_keys(array_diff_key($before, $after)) as $id) {
            $verbs[(string)$id] = EventVerb::Deleted;
        }

        return $verbs;
    }
}
