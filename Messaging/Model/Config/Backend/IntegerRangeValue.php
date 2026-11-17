<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

abstract class IntegerRangeValue extends Value
{
    /**
     * Refuse a value that is not a whole number inside the field's range.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave(): self
    {
        // Server side on purpose: a direct POST skips the form validation and can even send an array.
        $value = $this->getValue();
        if (!is_scalar($value)) {
            $value = '';
        }
        $value = trim((string) $value);

        // filter_var, not a cast: "99999999999999999999" passes the digits check and (int) would saturate it to
        // PHP_INT_MAX, which a field without an upper bound would then store without a word.
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $this->getMinimum()]]);
        if (preg_match('/^\d+$/D', $value) !== 1 || $integer === false) {
            throw new LocalizedException($this->getRangeMessage());
        }

        $maximum = $this->getMaximum();
        if ($maximum !== null && $integer > $maximum) {
            throw new LocalizedException($this->getRangeMessage());
        }

        $this->setValue((string) $integer);

        return parent::beforeSave();
    }

    /**
     * @return int
     */
    abstract protected function getMinimum(): int;

    /**
     * Largest accepted value, or null when the field has no upper bound.
     *
     * @return int|null
     */
    abstract protected function getMaximum(): ?int;

    /**
     * @return string
     */
    protected function getFieldLabel(): string
    {
        $fieldConfig = $this->getFieldConfig();
        if (!is_array($fieldConfig) || empty($fieldConfig['label'])) {
            return (string) __('The value');
        }

        return (string) __($fieldConfig['label']);
    }

    /**
     * @return Phrase
     */
    private function getRangeMessage(): Phrase
    {
        $maximum = $this->getMaximum();
        if ($maximum === null) {
            return __('%1 must be a whole number, %2 or more.', $this->getFieldLabel(), $this->getMinimum());
        }

        return __('%1 must be a whole number from %2 to %3.', $this->getFieldLabel(), $this->getMinimum(), $maximum);
    }
}
