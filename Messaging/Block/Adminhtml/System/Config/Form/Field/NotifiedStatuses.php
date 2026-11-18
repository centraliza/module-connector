<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Block\Adminhtml\System\Config\Form\Field;

use Centraliza\Connector\Messaging\Block\Adminhtml\System\Config\Form\Field\Column\StatusColumn;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;

class NotifiedStatuses extends AbstractFieldArray
{
    private ?StatusColumn $statusRenderer = null;

    /**
     * @return void
     * @throws LocalizedException
     */
    protected function _prepareToRender(): void
    {
        $this->addColumn('status', [
            'label' => __('Order status'),
            'renderer' => $this->getStatusRenderer(),
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add status');
    }

    /**
     * @param DataObject $row
     * @return void
     * @throws LocalizedException
     */
    protected function _prepareArrayRow(DataObject $row): void
    {
        $extraAttributes = [];

        $status = $row->getStatus();
        if ($status !== null) {
            $extraAttributes['option_' . $this->getStatusRenderer()->calcOptionHash($status)] = 'selected="selected"';
        }

        $row->setData('option_extra_attrs', $extraAttributes);
    }

    /**
     * @return StatusColumn
     * @throws LocalizedException
     */
    private function getStatusRenderer(): StatusColumn
    {
        if ($this->statusRenderer !== null) {
            return $this->statusRenderer;
        }

        $renderer = $this->getLayout()->createBlock(
            StatusColumn::class,
            '',
            ['data' => ['is_render_to_js_template' => true]]
        );
        if (!$renderer instanceof StatusColumn) {
            throw new LocalizedException(__('The order status column could not be created.'));
        }

        return $this->statusRenderer = $renderer;
    }
}
