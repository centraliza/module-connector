<?php

declare(strict_types=1);

namespace Centraliza\Connector\Entity;

use Magento\Eav\Model\Config as EavConfig;
use Throwable;

/**
 * An attribute chosen from a list is stored as the id of the option; this
 * puts the text the merchant sees next to it.
 */
class AttributeLabels
{
    private const OPTION_INPUTS = ['select', 'multiselect'];

    public function __construct(
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * @param array<string, mixed> $attributes by code
     * @return array<string, mixed> the same, with {value, label} where the attribute has options
     */
    public function withLabels(string $entityType, array $attributes, int $storeId): array
    {
        foreach ($attributes as $code => $value) {
            $attributes[$code] = $this->labeled($entityType, (string)$code, $value, $storeId);
        }

        return $attributes;
    }

    /**
     * The text of one option of an attribute, given by code or by id; null when there is none.
     */
    public function optionLabel(string $entityType, string|int $attribute, mixed $optionId, int $storeId): ?string
    {
        try {
            $attribute = $this->eavConfig->getAttribute($entityType, $attribute);
            if (!$attribute->getId() || !$attribute->usesSource()) {
                return null;
            }
            $attribute->setData('store_id', $storeId);

            return $this->text($attribute->getSource()->getOptionText($optionId));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Sources do not agree on what they answer: a string, false for no such
     * option, a translatable phrase, or a list of labels.
     */
    private function text(mixed $label): ?string
    {
        if (is_array($label)) {
            $label = $label['label'] ?? reset($label);
        }
        if (!is_scalar($label) && !$label instanceof \Stringable) {
            return null;
        }
        $text = $label === false ? '' : (string)$label;

        return $text === '' ? null : $text;
    }

    /**
     * @return mixed the value as given, or {value, label} when the attribute has options
     */
    public function labeled(string $entityType, string $code, mixed $value, int $storeId): mixed
    {
        if ($value === null || $value === '' || is_array($value)) {
            return $value;
        }
        try {
            $attribute = $this->eavConfig->getAttribute($entityType, $code);
            if (!$attribute->getId()
                || !in_array($attribute->getFrontendInput(), self::OPTION_INPUTS, true)
                || !$attribute->usesSource()
            ) {
                return $value;
            }
            // The option text is the one of the store view that reports.
            $attribute->setData('store_id', $storeId);
            $source = $attribute->getSource();
            $label = fn (mixed $id): ?string => $this->text($source->getOptionText($id));
            if ($attribute->getFrontendInput() === 'multiselect') {
                return array_map(
                    static fn (string $id): array => ['value' => $id, 'label' => $label($id)],
                    explode(',', (string)$value)
                );
            }

            return ['value' => $value, 'label' => $label($value)];
        } catch (Throwable) {
            // A label is a convenience: the value alone is still the truth.
            return $value;
        }
    }
}
