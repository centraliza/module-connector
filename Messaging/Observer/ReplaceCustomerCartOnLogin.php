<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Observer;

use Centraliza\Connector\Messaging\Model\LegacyModules;
use Centraliza\Connector\Messaging\Api\ReplaceCartServiceInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Throwable;

class ReplaceCustomerCartOnLogin implements ObserverInterface
{
    /**
     * @param ReplaceCartServiceInterface $replaceCartService
     * @param LoggerInterface $logger
     * @param LegacyModules $legacyModules
     */
    public function __construct(
        private readonly ReplaceCartServiceInterface $replaceCartService,
        private readonly LoggerInterface $logger,
        private readonly LegacyModules $legacyModules
    ) {
    }

    /**
     * Runs inside the core login, right before it looks for the customer's cart to merge into.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        // Centraliza_Sales does the same while it is enabled; doing it twice would replace or move the cart twice.
        if ($this->legacyModules->areActive()) {
            return;
        }

        // The login must go on whatever fails here; the core then merges the carts as it does without a link.
        try {
            $this->replaceCartService->replaceOnLogin();
        } catch (LocalizedException) {
            // Already logged by the service with its reason.
        } catch (Throwable $exception) {
            $this->logger->error('Centraliza cart redirect could not replace the cart on login', [
                'exception_class' => $exception::class,
            ]);
        }
    }
}
