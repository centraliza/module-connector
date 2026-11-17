<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Api\Data;

interface ConnectionResultInterface
{
    /**
     * @return bool
     */
    public function isValid(): bool;

    /**
     * @return string|null
     */
    public function getWorkspaceId(): ?string;

    /**
     * @return string|null
     */
    public function getWorkspaceName(): ?string;

    /**
     * @return string|null
     */
    public function getErrorMessage(): ?string;

    /**
     * True when Centraliza refused the key itself, as opposed to being unreachable.
     *
     * @return bool
     */
    public function isRejected(): bool;

    /**
     * True when no check was made: the 60 s check interval or the key's quota made it wait.
     *
     * @return bool
     */
    public function isThrottled(): bool;
}
