<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

/**
 * Writes every Centraliza module's log entries to their own file, apart from the store's system.log.
 */
class Handler extends Base
{
    /**
     * @var string
     */
    protected $fileName = '/var/log/centraliza.log';

    /**
     * @var int
     */
    protected $loggerType = Logger::INFO;
}
