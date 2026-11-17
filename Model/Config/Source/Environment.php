<?php

declare(strict_types=1);

namespace Centraliza\Connector\Model\Config\Source;

use Centraliza\Connector\Infra\Config\Settings;
use Magento\Framework\Data\OptionSourceInterface;

class Environment implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Settings::ENV_PRODUCTION, 'label' => __('Production (app.centraliza.ai)')],
            ['value' => Settings::ENV_STAGING, 'label' => __('Staging (app.centraliza.dev)')],
        ];
    }
}
