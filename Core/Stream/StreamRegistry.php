<?php

declare(strict_types=1);

namespace Centraliza\Connector\Core\Stream;

use InvalidArgumentException;

/**
 * The streams this store reports, with the queue lane and the key fields of
 * each. Filled from di.xml, so a new stream needs no change here.
 */
final readonly class StreamRegistry
{
    /**
     * @param array<string, array{lane: string, key: string[], optional?: string[]}> $streams
     */
    public function __construct(
        private array $streams = []
    ) {
    }

    public function has(string $stream): bool
    {
        return isset($this->streams[$stream]);
    }

    public function lane(string $stream): string
    {
        return $this->definition($stream)['lane'];
    }

    /**
     * Returns the key with exactly the fields the stream declares, in the declared order.
     * Optional fields are kept only when they have a value.
     *
     * @param array<string, mixed> $key
     * @return array<string, scalar>
     */
    public function key(string $stream, array $key): array
    {
        $normalized = [];
        foreach ($this->definition($stream)['key'] as $field) {
            $value = $key[$field] ?? null;
            if (!is_scalar($value) || $value === '') {
                throw new InvalidArgumentException(sprintf('Stream "%s" needs key field "%s".', $stream, $field));
            }
            $normalized[$field] = $value;
        }
        foreach ($this->definition($stream)['optional'] ?? [] as $field) {
            $value = $key[$field] ?? null;
            if (is_scalar($value) && $value !== '') {
                $normalized[$field] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @return array{lane: string, key: string[], optional?: string[]}
     */
    private function definition(string $stream): array
    {
        return $this->streams[$stream]
            ?? throw new InvalidArgumentException(sprintf('Unknown stream "%s".', $stream));
    }
}
