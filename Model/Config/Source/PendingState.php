<?php

declare(strict_types=1);

namespace Centraliza\Connector\Model\Config\Source;

use Centraliza\Connector\Infra\Persistence\PendingEventsGrid;
use Magento\Framework\Data\OptionSourceInterface;

class PendingState implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => PendingEventsGrid::STATE_QUEUED, 'label' => __('On the queue')],
            ['value' => PendingEventsGrid::STATE_SENDING, 'label' => __('Being sent')],
            ['value' => PendingEventsGrid::STATE_HELD, 'label' => __('Waiting for a new attempt')],
            ['value' => PendingEventsGrid::STATE_QUARANTINED, 'label' => __('Quarantine')],
        ];
    }
}
