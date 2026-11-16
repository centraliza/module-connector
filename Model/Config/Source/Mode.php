<?php

declare(strict_types=1);

namespace Centraliza\Connector\Model\Config\Source;

use Centraliza\Connector\Infra\Config\Settings;
use Magento\Framework\Data\OptionSourceInterface;

class Mode implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Settings::MODE_DRY_RUN, 'label' => __('Simulation (log only, nothing is sent)')],
            ['value' => Settings::MODE_LIVE, 'label' => __('Live (send to Centraliza)')],
        ];
    }
}
