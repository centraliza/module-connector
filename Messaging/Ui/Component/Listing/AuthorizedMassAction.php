<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Ui\Component\Listing;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Ui\Component\MassAction;

/**
 * Mass action that hides each action whose aclResource the admin user is not allowed.
 */
class AuthorizedMassAction extends MassAction
{
    /**
     * @param ContextInterface $context
     * @param AuthorizationInterface $authorization
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        private readonly AuthorizationInterface $authorization,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $components, $data);
    }

    /**
     * @return void
     */
    public function prepare(): void
    {
        // Core reads no aclResource on a mass action, only actionDisable; the controller still
        // checks its own ADMIN_RESOURCE.
        foreach ($this->getChildComponents() as $actionComponent) {
            $config = $actionComponent->getConfiguration();
            if (empty($config['aclResource'])) {
                continue;
            }

            $config['actionDisable'] = !$this->authorization->isAllowed($config['aclResource']);
            $actionComponent->setData('config', $config);
        }

        parent::prepare();
    }
}
