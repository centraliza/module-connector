<?php

declare(strict_types=1);

namespace Centraliza\Connector\Model\Config\Source;

use Centraliza\Connector\Core\Model\Action;
use Magento\Framework\Data\OptionSourceInterface;

class DeliveryAction implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Action::Delivered->value, 'label' => __('Delivered')],
            ['value' => Action::Retry->value, 'label' => __('New attempt later')],
            ['value' => Action::Postpone->value, 'label' => __('Waiting, no attempt spent')],
            ['value' => Action::Quarantine->value, 'label' => __('Quarantined')],
            ['value' => Action::Skipped->value, 'label' => __('Skipped')],
        ];
    }
}
