<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Service;

/**
 * Tells which fields of a record really changed between what was loaded and
 * what was saved, so a save that changes nothing reports nothing.
 */
final class RecordDiff
{
    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $original
     * @param string[] $ignored fields that change on every save or carry no content
     * @param string[] $unordered list fields whose order means nothing
     * @return string[] names of the fields that differ
     */
    public function changedFields(array $current, array $original, array $ignored = [], array $unordered = []): array
    {
        $changed = [];
        $fields = array_diff(array_unique(array_merge(array_keys($current), array_keys($original))), $ignored);

        foreach ($fields as $field) {
            $now = $current[$field] ?? null;
            $before = $original[$field] ?? null;

            if (is_object($now) || is_object($before)) {
                continue;
            }
            if (!$this->same($now, $before, in_array($field, $unordered, true))) {
                $changed[] = (string)$field;
            }
        }

        return $changed;
    }

    private function same(mixed $now, mixed $before, bool $unordered): bool
    {
        if (is_array($now) || is_array($before)) {
            return $this->normalized((array)$now, $unordered) === $this->normalized((array)$before, $unordered);
        }
        if (is_bool($now) || is_bool($before)) {
            return (bool)$now === (bool)$before;
        }
        if (is_numeric($now) && is_numeric($before)) {
            return (float)$now === (float)$before;
        }

        // The database returns strings and nulls where the form sends '', 0 or false.
        return (string)$now === (string)$before;
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private function normalized(array $value, bool $unordered): string
    {
        $value = $this->canonical($value);
        if ($unordered) {
            sort($value);
        }

        return (string)json_encode($value);
    }

    private function canonical(mixed $value): mixed
    {
        if (is_object($value)) {
            return null;
        }
        if (!is_array($value)) {
            return is_numeric($value) ? (string)(float)$value : (string)$value;
        }
        $value = array_map($this->canonical(...), $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
