<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Auth;

use Centraliza\Connector\Infra\Config\Settings;
use Centraliza\Connector\Messaging\Api\Data\ConnectionResultInterface;
use Centraliza\Connector\Messaging\Api\ScopeResolverInterface;
use Centraliza\Connector\Messaging\Model\Config;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use SensitiveParameter;

/**
 * Writes the validation state of a website's key, at that website's scope (Default Config for a null website),
 * one per environment.
 */
class ConnectionStatus
{
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * @param WriterInterface $configWriter
     * @param ReinitableConfigInterface $reinitableConfig
     * @param EncryptorInterface $encryptor
     * @param Config $config
     * @param Settings $settings
     * @param DateTime $dateTime
     * @param RejectedKeyStore $rejectedKeyStore
     * @param ScopeResolverInterface $scopeResolver
     */
    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly Config $config,
        private readonly Settings $settings,
        private readonly DateTime $dateTime,
        private readonly RejectedKeyStore $rejectedKeyStore,
        private readonly ScopeResolverInterface $scopeResolver
    ) {
    }

    /**
     * Store the validated key and the resulting connection state of the website's environment.
     *
     * @param ConnectionResultInterface $result
     * @param string $apiKey
     * @param int|null $websiteId
     * @param bool $storeKey false when the validated key is the one already stored
     * @return void
     */
    public function persist(
        ConnectionResultInterface $result,
        #[SensitiveParameter] string $apiKey,
        ?int $websiteId,
        bool $storeKey = true
    ): void {
        // Revalidating the stored key must not write it back: on a website that inherits the default key, a copy
        // saved at website scope would stop following the default.
        if ($storeKey) {
            $this->save($this->settings->apiKeyPath($websiteId), $this->encryptor->encrypt($apiKey), $websiteId);
        }
        $this->save(
            $this->config->forEnvironment(Config::PATH_CONNECTION_WORKSPACE_NAME, $websiteId),
            (string) $result->getWorkspaceName(),
            $websiteId
        );
        $this->save(
            $this->config->forEnvironment(Config::PATH_CONNECTION_VALIDATED_AT, $websiteId),
            $this->dateTime->gmtDate(self::TIMESTAMP_FORMAT),
            $websiteId
        );

        // Last on purpose: isConnected() gates only on the API key hash, so an interruption before this line
        // leaves the old hash in place, which will not match the new key — the state fails closed to
        // "changed/pending" instead of showing a connected screen for a validation that never completed.
        $this->save(
            $this->config->forEnvironment(Config::PATH_CONNECTION_KEY_HASH, $websiteId),
            $this->config->hashApiKey($apiKey),
            $websiteId
        );

        // Only after the hash: clearing the rejection earlier would let a request that dies mid-rotation leave a
        // refused key whose hash still matches reading as connected.
        $this->rejectedKeyStore->remove($this->config->hashApiKey($apiKey));

        $this->refresh();
    }

    /**
     * The rejected-key map is left alone: it describes keys, not this website.
     *
     * @param int|null $websiteId
     * @return void
     */
    public function clear(?int $websiteId): void
    {
        [$scope, $scopeId] = $this->scopeResolver->forWrite($websiteId);
        foreach ([
            Config::PATH_CONNECTION_KEY_HASH,
            Config::PATH_CONNECTION_WORKSPACE_NAME,
            Config::PATH_CONNECTION_VALIDATED_AT,
        ] as $path) {
            $this->configWriter->delete($this->config->forEnvironment($path, $websiteId), $scope, $scopeId);
        }

        $this->refresh();
    }

    /**
     * Record that Centraliza refused this key, against the key's hash.
     *
     * @param int|null $websiteId
     * @param string $apiKey
     * @return void
     */
    public function markRejected(?int $websiteId, #[SensitiveParameter] string $apiKey): void
    {
        $this->rejectedKeyStore->add(
            $this->config->hashApiKey($apiKey),
            $this->dateTime->gmtDate(self::TIMESTAMP_FORMAT)
        );

        $this->refresh();
    }

    /**
     * @param string $path
     * @param string $value
     * @param int|null $websiteId
     * @return void
     */
    private function save(string $path, string $value, ?int $websiteId): void
    {
        [$scope, $scopeId] = $this->scopeResolver->forWrite($websiteId);
        $this->configWriter->save($path, $value, $scope, $scopeId);
    }

    /**
     * @return void
     */
    private function refresh(): void
    {
        // WriterInterface::save does not invalidate the config cache: without this the database is right and
        // the screen is wrong.
        $this->reinitableConfig->reinit();
    }
}
