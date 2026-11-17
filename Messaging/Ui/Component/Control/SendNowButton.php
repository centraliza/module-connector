<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Ui\Component\Control;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class SendNowButton implements ButtonProviderInterface
{
    /**
     * @param AuthorizationInterface $authorization
     * @param UrlInterface $urlBuilder
     * @param Escaper $escaper
     * @param string $actionPath
     * @param string $aclResource
     */
    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly UrlInterface $urlBuilder,
        private readonly Escaper $escaper,
        private readonly string $actionPath,
        private readonly string $aclResource
    ) {
    }

    /**
     * @return array
     */
    public function getButtonData(): array
    {
        if (!$this->authorization->isAllowed($this->aclResource)) {
            return [];
        }

        $confirmMessage = $this->escaper->escapeJs($this->escaper->escapeHtml(
            __('Run the send round now? The waiting rows are sent to Centraliza within the key quota.')
        ));
        $url = $this->escaper->escapeJs($this->urlBuilder->getUrl($this->actionPath));

        return [
            'label' => __('Send now'),
            'class' => 'primary',
            // POST with the form key: each send messages customers, so it is never a plain link.
            'on_click' => 'deleteConfirm(\'' . $confirmMessage . '\', \'' . $url . '\', {"data": {}})',
            'sort_order' => 10,
        ];
    }
}
