<?php

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model;

use Magento\Framework\Module\Manager as ModuleManager;

/**
 * Whether the legacy Centraliza modules still run the messaging flows ported here.
 *
 * Both can be installed at once, so the ported flows stay off while Centraliza_Sales is enabled: the cutover
 * is then one deploy that disables the legacy modules in app/etc/config.php.
 */
class LegacyModules
{
    /**
     * Sends the order notifications and the abandoned carts, and serves the cart recovery link.
     */
    public const SALES = 'Centraliza_Sales';

    /**
     * @param ModuleManager $moduleManager
     */
    public function __construct(
        private readonly ModuleManager $moduleManager
    ) {
    }

    /**
     * @return bool
     */
    public function areActive(): bool
    {
        return $this->moduleManager->isEnabled(self::SALES);
    }
}
