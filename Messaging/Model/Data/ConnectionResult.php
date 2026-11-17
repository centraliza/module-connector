<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Data;

use Centraliza\Connector\Messaging\Api\Data\ConnectionResultInterface;

class ConnectionResult implements ConnectionResultInterface
{
    /**
     * @param bool $valid
     * @param string|null $workspaceId
     * @param string|null $workspaceName
     * @param string|null $errorMessage
     * @param bool $rejected
     * @param bool $throttled
     */
    public function __construct(
        private readonly bool $valid = false,
        private readonly ?string $workspaceId = null,
        private readonly ?string $workspaceName = null,
        private readonly ?string $errorMessage = null,
        private readonly bool $rejected = false,
        private readonly bool $throttled = false
    ) {
    }

    /**
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * @return string|null
     */
    public function getWorkspaceId(): ?string
    {
        return $this->workspaceId;
    }

    /**
     * @return string|null
     */
    public function getWorkspaceName(): ?string
    {
        return $this->workspaceName;
    }

    /**
     * @return string|null
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * @return bool
     */
    public function isRejected(): bool
    {
        return $this->rejected;
    }

    /**
     * @return bool
     */
    public function isThrottled(): bool
    {
        return $this->throttled;
    }
}
