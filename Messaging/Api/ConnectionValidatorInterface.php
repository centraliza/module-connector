<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api;

use Centraliza\Connector\Messaging\Api\Data\ConnectionResultInterface;
use SensitiveParameter;

interface ConnectionValidatorInterface
{
    /**
     * Validate the given key against the Centraliza API without persisting it.
     *
     * @param string $apiKey
     * @param int|null $websiteId
     * @param bool $isStoredKey true for the key saved for the scope, which nobody typed
     * @return ConnectionResultInterface
     */
    public function validate(
        #[SensitiveParameter] string $apiKey,
        ?int $websiteId = null,
        bool $isStoredKey = false
    ): ConnectionResultInterface;
}
