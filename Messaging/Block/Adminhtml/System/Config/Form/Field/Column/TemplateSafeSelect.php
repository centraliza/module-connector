<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Block\Adminhtml\System\Config\Form\Field\Column;

use Magento\Framework\View\Element\Html\Select;

/**
 * Dynamic-rows select: mage/template turns "%3C%" into "<%", so an admin-edited "%" could run as script.
 */
abstract class TemplateSafeSelect extends Select
{
    /**
     * Reads the same to a person and is not part of the "<%" delimiter.
     */
    private const SAFE_PERCENT = '％';

    /**
     * Select prints a param key unescaped as an attribute name: only a plain name is kept.
     */
    private const ATTRIBUTE_NAME_PATTERN = '/^[a-z-]+$/D';

    /**
     * @return string
     */
    protected function _toHtml(): string
    {
        // Select::_toHtml() prints an optgroup's label and name itself, without _optionToHtml().
        $options = [];
        foreach ($this->getOptions() as $key => $option) {
            if (is_array($option) && is_array($option['value'] ?? null)) {
                $option['label'] = str_replace('%', self::SAFE_PERCENT, (string) ($option['label'] ?? ''));
                if (isset($option['optgroup-name'])) {
                    $option['optgroup-name'] = str_replace('%', self::SAFE_PERCENT, (string) $option['optgroup-name']);
                }
            }
            $options[$key] = $option;
        }
        $this->setOptions($options);

        return parent::_toHtml();
    }

    /**
     * @param array $option
     * @param bool $selected
     * @return string
     */
    protected function _optionToHtml($option, $selected = false): string
    {
        // Only a crafted admin save puts "%" in a code or a param; rewriting it would save a different value.
        if (str_contains((string) ($option['value'] ?? ''), '%')) {
            return '';
        }
        foreach ((array) ($option['params'] ?? []) as $name => $param) {
            $attributes = [$name => $param];
            if (is_array($param)) {
                $attributes = $param;
            }
            foreach ($attributes as $attributeName => $attributeValue) {
                if (!preg_match(self::ATTRIBUTE_NAME_PATTERN, (string) $attributeName)
                    || str_contains((string) $attributeValue, '%')
                ) {
                    return '';
                }
            }
        }

        $option['label'] = str_replace('%', self::SAFE_PERCENT, (string) ($option['label'] ?? ''));

        return parent::_optionToHtml($option, $selected);
    }
}
