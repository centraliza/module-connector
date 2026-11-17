<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity;

use Magento\Framework\Webapi\ServiceOutputProcessor;

/**
 * Turns what a Magento service returns into the array the REST API would
 * answer with. That representation has every attribute of the record,
 * including the ones other modules add as custom or extension attributes.
 */
class ServiceRecord
{
    private const CUSTOM_ATTRIBUTES = 'custom_attributes';

    public function __construct(
        private readonly ServiceOutputProcessor $outputProcessor
    ) {
    }

    /**
     * @param mixed $result what the service method returned
     * @param class-string $serviceInterface
     * @return array<string, mixed>
     */
    public function toArray(mixed $result, string $serviceInterface, string $method): array
    {
        /** @var array<string, mixed> $record */
        $record = $this->named((array)$this->outputProcessor->process($result, $serviceInterface, $method));

        return $record;
    }

    /**
     * The REST API lists custom attributes as {attribute_code, value} pairs;
     * here each one is a field named after its code, at every level of the record.
     *
     * @param array<int|string, mixed> $data
     * @return array<int|string, mixed>
     */
    private function named(array $data): array
    {
        foreach ($data as $field => $value) {
            if (!is_array($value)) {
                continue;
            }
            if ($field === self::CUSTOM_ATTRIBUTES) {
                $attributes = [];
                foreach ($value as $attribute) {
                    if (is_array($attribute) && isset($attribute['attribute_code'])) {
                        $attributes[(string)$attribute['attribute_code']] = $attribute['value'] ?? null;
                    }
                }
                ksort($attributes);
                $data[$field] = $attributes;
                continue;
            }
            $data[$field] = $this->named($value);
        }

        return $data;
    }
}
