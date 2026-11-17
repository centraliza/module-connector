<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Api;

use Centraliza\Connector\Messaging\Api\ApiKeyGroupingInterface;
use Centraliza\Connector\Messaging\Api\ConfigInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class ApiKeyGrouping implements ApiKeyGroupingInterface
{
    /**
     * @param ConfigInterface $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param int[] $websiteIds
     * @return array<string, int[]>
     */
    public function groupWebsitesByApiKey(array $websiteIds): array
    {
        $groups = [];
        foreach ($websiteIds as $websiteId) {
            $websiteId = (int) $websiteId;

            try {
                if (!$this->config->isReady($websiteId)) {
                    continue;
                }

                $apiKey = $this->config->getApiKey($websiteId);
            } catch (Throwable $exception) {
                // A website whose key cannot be read is left out, so the others are still grouped.
                // Only the class is logged: the message could quote configuration data.
                $this->logger->warning('Centraliza website left out of the key grouping', [
                    'website_id' => $websiteId,
                    'exception_class' => $exception::class,
                ]);

                continue;
            }

            if (empty($apiKey)) {
                continue;
            }

            $groups[$this->config->hashApiKey($apiKey)][] = $websiteId;
        }

        return $groups;
    }
}
