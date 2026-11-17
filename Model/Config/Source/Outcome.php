<?php

declare(strict_types=1);

namespace Centraliza\Connector\Model\Config\Source;

use Centraliza\Connector\Core\Model\OutcomeKind;
use Magento\Framework\Data\OptionSourceInterface;

class Outcome implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => OutcomeKind::Accepted->value, 'label' => __('Accepted')],
            ['value' => OutcomeKind::Rejected->value, 'label' => __('Rejected (invalid)')],
            ['value' => OutcomeKind::RateLimited->value, 'label' => __('Too many requests')],
            ['value' => OutcomeKind::Unauthorized->value, 'label' => __('Key refused')],
            ['value' => OutcomeKind::RouteRefused->value, 'label' => __('Route or channel refused')],
            ['value' => OutcomeKind::Unavailable->value, 'label' => __('Centraliza unavailable')],
        ];
    }
}
