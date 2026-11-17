<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Block\Adminhtml\System\Config;

use Centraliza\Connector\Infra\Config\Settings;
use Centraliza\Connector\Messaging\Api\ConfigInterface;
use Centraliza\Connector\Messaging\Api\ScopeResolverInterface;
use Centraliza\Connector\Messaging\Model\Auth\ConnectionPresenter;
use Centraliza\Connector\Messaging\Model\LegacyModules;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Phrase;

class AuthenticateButton extends Field
{
    /**
     * @var string
     */
    protected $_template = 'Centraliza_Connector::system/config/authenticate-button.phtml';

    /**
     * @var string|null
     */
    private ?string $elementId = null;

    /**
     * @param Context $context
     * @param ConnectionPresenter $connectionPresenter
     * @param ConfigInterface $config
     * @param ScopeResolverInterface $scopeResolver
     * @param Settings $settings
     * @param LegacyModules $legacyModules
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly ConnectionPresenter $connectionPresenter,
        private readonly ConfigInterface $config,
        private readonly ScopeResolverInterface $scopeResolver,
        private readonly Settings $settings,
        private readonly LegacyModules $legacyModules,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element): string
    {
        // Three unsets, matching Tera_BlingIntegration's ConnectButton: unsetScope alone
        // still renders the "Use Website"/"Use Default" checkboxes beside a field that
        // shows state rather than holding a value, where inheriting means nothing.
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        // Magento renders <label for="{$element->getHtmlId()}"> for this field
        // regardless of what the frontend model outputs. Capturing the id here and
        // putting it on the button, instead of hardcoding the field path as a
        // literal, is what keeps that label pointing at something.
        $this->elementId = $element->getHtmlId();

        return parent::render($element);
    }

    /**
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    /**
     * @return int|null
     */
    public function getWebsiteId(): ?int
    {
        return $this->scopeResolver->getWebsiteIdFromParam($this->getRequest()->getParam('website'));
    }

    /**
     * Resolves the already-decided connection status message for this scope, so
     * the template only escapes and prints — it never chooses between states.
     *
     * @return Phrase
     */
    public function getStatusMessage(): Phrase
    {
        return $this->connectionPresenter->forScope($this->getWebsiteId())['message'];
    }

    /**
     * @return string
     */
    public function getValidateUrl(): string
    {
        return $this->getUrl('centraliza_connector/connection/validate');
    }

    /**
     * Inline colour for the status line, matching Tera_BlingIntegration's ConnectButton
     * precedent. Never a grid-severity-* class: those are grid-only and break the
     * layout inside a config form.
     *
     * @return string|null
     */
    public function getStatusColor(): ?string
    {
        return $this->connectionPresenter->forScope($this->getWebsiteId())['color'];
    }

    /**
     * Html id Magento assigned to this field's element, so the button carries the
     * same id the automatically rendered <label for="..."> already points to.
     *
     * @return string|null
     */
    public function getElementId(): ?string
    {
        return $this->elementId;
    }

    /**
     * Html id of the API key field of the saved environment, derived from its config path the same way Magento
     * derives every config field's own id, so it cannot silently stop matching.
     *
     * @return string
     */
    public function getApiKeyFieldId(): string
    {
        return str_replace('/', '_', $this->settings->apiKeyPath());
    }

    /**
     * Html id of the environment select: the check covers the saved environment only, so the script stops when
     * the select was changed and not saved yet.
     *
     * @return string
     */
    public function getEnvironmentFieldId(): string
    {
        return 'centraliza_connector_general_environment';
    }

    /**
     * @return string
     */
    public function getEnvironment(): string
    {
        return $this->settings->environment();
    }

    /**
     * Set while the legacy modules still send the messages: this connection is checked but not used for them.
     *
     * @return Phrase|null
     */
    public function getLegacyNotice(): ?Phrase
    {
        if (!$this->legacyModules->areActive()) {
            return null;
        }

        return __(
            'The legacy Centraliza modules are enabled, so they still send the order notifications and abandoned carts and serve the cart recovery link. This module takes over once they are disabled.'
        );
    }

    /**
     * @return bool
     */
    public function hasStoredKey(): bool
    {
        return $this->config->getApiKey($this->getWebsiteId()) !== null;
    }
}
