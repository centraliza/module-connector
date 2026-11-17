<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Controller\Adminhtml\Connection;

use Centraliza\Connector\Messaging\Api\ConfigInterface;
use Centraliza\Connector\Messaging\Api\ConnectionValidatorInterface;
use Centraliza\Connector\Messaging\Api\ScopeResolverInterface;
use Centraliza\Connector\Messaging\Api\StoreScopeInterface;
use Centraliza\Connector\Messaging\Model\Auth\ConnectionPresenter;
use Centraliza\Connector\Messaging\Model\Auth\ConnectionStatus;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;

class Validate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Centraliza_Connector::config';

    /**
     * The key's 60 requests per minute are shared with sending: one automatic check per scope every five minutes.
     */
    private const AUTO_CHECK_WINDOW_SECONDS = 300;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param ConnectionValidatorInterface $connectionValidator
     * @param ConnectionStatus $connectionStatus
     * @param ConnectionPresenter $connectionPresenter
     * @param ConfigInterface $config
     * @param StoreScopeInterface $storeScope
     * @param DateTime $dateTime
     * @param ScopeResolverInterface $scopeResolver
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly ConnectionValidatorInterface $connectionValidator,
        private readonly ConnectionStatus $connectionStatus,
        private readonly ConnectionPresenter $connectionPresenter,
        private readonly ConfigInterface $config,
        private readonly StoreScopeInterface $storeScope,
        private readonly DateTime $dateTime,
        private readonly ScopeResolverInterface $scopeResolver
    ) {
        parent::__construct($context);
    }

    /**
     * @return Json
     */
    public function execute(): Json
    {
        $request = $this->getRequest();
        // POST body only: a key must never travel in a URL, which access logs keep.
        $websiteParam = $request->getPostValue('website');
        $apiKeyParam = $request->getPostValue('api_key');

        // An array payload (api_key[]=x) is rejected instead of coerced with a warning.
        $websiteParamIsInvalid = $websiteParam !== null && !is_scalar($websiteParam);
        $apiKeyParamIsInvalid = $apiKeyParam !== null && !is_scalar($apiKeyParam);
        if ($websiteParamIsInvalid || $apiKeyParamIsInvalid) {
            return $this->invalidRequest();
        }

        $websiteId = $this->scopeResolver->getWebsiteIdFromParam($websiteParam);

        // A malformed website must not fall back to the default scope. Website '0' resolves to 0, not null.
        if ($websiteId === null && !empty($websiteParam)) {
            return $this->invalidRequest();
        }

        // An unknown website would make the config reader and writer raise. Not empty(): website 0 must be refused.
        if ($websiteId !== null && !$this->storeScope->websiteExists($websiteId)) {
            return $this->invalidRequest();
        }

        $useStored = $request->getPostValue('use_stored') === 'true';

        $auto = $useStored && $request->getPostValue('auto') === 'true';

        if ($auto && $this->config->getRejectedAt($websiteId) !== null) {
            // Already known to be refused: asking again spends quota and moves rejected_at forward.
            return $this->respond(false, $this->connectionPresenter->forScope($websiteId));
        }

        $validatedAt = $this->config->getValidatedAt($websiteId);
        $now = (int) $this->dateTime->gmtTimestamp();

        // A key saved through Save Config keeps the previous key's validated_at, so isConnected() must hold too.
        if ($auto && $this->isFresh($validatedAt, $now) && $this->config->isConnected($websiteId)) {
            return $this->respond(true, $this->connectionPresenter->forScope($websiteId));
        }

        // Never log getParams(): it holds the key. Trimmed once, so the key validated is the key persisted.
        $apiKey = trim((string) $apiKeyParam);
        if ($useStored) {
            $apiKey = trim((string) $this->config->getApiKey($websiteId));
        }

        $result = $this->connectionValidator->validate($apiKey, $websiteId, $useStored);

        if (!$result->isValid()) {
            // A throttled automatic check learnt nothing, so it is not an outage: the last known state stands.
            if ($auto && $result->isThrottled()) {
                return $this->respond(
                    $this->config->isConnected($websiteId),
                    $this->connectionPresenter->forScope($websiteId)
                );
            }

            // Only an automatic check tests the stored key; a manual failure may concern a key never saved.
            // An outage keeps the last known state.
            if ($auto && $result->isRejected()) {
                $this->connectionStatus->markRejected($websiteId, $apiKey);

                return $this->respond(false, $this->connectionPresenter->forScope($websiteId));
            }

            if ($auto) {
                return $this->respond(false, $this->connectionPresenter->forUnavailable($websiteId));
            }

            return $this->respond(false, $this->connectionPresenter->forFailure((string) $result->getErrorMessage()));
        }

        $this->connectionStatus->persist($result, $apiKey, $websiteId, !$useStored);

        // From the persisted state, so a reload renders the same message and colour.
        $presentation = $this->connectionPresenter->forScope($websiteId);

        return $this->resultJsonFactory->create()->setData([
            'success' => true,
            'workspace' => $result->getWorkspaceName(),
            'message' => (string) $presentation['message'],
            'color' => $presentation['color'],
        ]);
    }

    /**
     * @return Json
     */
    private function invalidRequest(): Json
    {
        $presentation = $this->connectionPresenter->forFailure((string) __('Invalid request.'));

        return $this->resultJsonFactory->create()->setData([
            'success' => false,
            'message' => (string) $presentation['message'],
            'color' => $presentation['color'],
        ]);
    }

    /**
     * @param string|null $validatedAt
     * @param int $now
     * @return bool
     */
    private function isFresh(?string $validatedAt, int $now): bool
    {
        if ($validatedAt === null) {
            return false;
        }

        $timestamp = strtotime($validatedAt);

        return $timestamp !== false && $now - $timestamp < self::AUTO_CHECK_WINDOW_SECONDS;
    }

    /**
     * @param bool $success
     * @param array $presentation
     * @return Json
     */
    private function respond(bool $success, array $presentation): Json
    {
        return $this->resultJsonFactory->create()->setData([
            'success' => $success,
            'message' => (string) $presentation['message'],
            'color' => $presentation['color'],
        ]);
    }
}
