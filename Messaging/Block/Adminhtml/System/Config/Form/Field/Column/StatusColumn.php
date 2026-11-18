<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Block\Adminhtml\System\Config\Form\Field\Column;

use Centraliza\Connector\Messaging\Block\Adminhtml\System\Config\Form\Field\Column\TemplateSafeSelect;
use Magento\Framework\View\Element\Context;
use Magento\Sales\Model\Config\Source\Order\Status;

class StatusColumn extends TemplateSafeSelect
{
    /**
     * @param Context $context
     * @param Status $statusSource
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly Status $statusSource,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @param string $value
     * @return $this
     */
    public function setInputName(string $value): self
    {
        return $this->setName($value);
    }

    /**
     * @param string $value
     * @return $this
     */
    public function setInputId(string $value): self
    {
        return $this->setId($value);
    }

    /**
     * @return string
     */
    protected function _toHtml(): string
    {
        if (!$this->getOptions()) {
            $this->setOptions($this->statusSource->toOptionArray());
        }

        return parent::_toHtml();
    }
}
