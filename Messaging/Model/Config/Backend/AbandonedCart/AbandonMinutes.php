<?php
/**
 * Copyright © Centraliza. All rights reserved.
 */

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Config\Backend\AbandonedCart;

use Centraliza\Connector\Messaging\Model\AbandonedCart\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;

class AbandonMinutes extends Minutes
{
    /**
     * Refuse a value of minutes not under the send limit.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave(): self
    {
        parent::beforeSave();

        $sendLimitMinutes = $this->getSendLimitMinutes();
        if ($sendLimitMinutes === Config::NO_SEND_LIMIT) {
            return $this;
        }

        // From the send limit on, no cart is both abandoned and still sendable.
        $maxMinutes = $sendLimitMinutes - 1;
        if ((int) $this->getValue() > $maxMinutes) {
            throw new LocalizedException(
                __(
                    '%1 must be a whole number of minutes from %2 to %3, under the idle cart limit.',
                    $this->getFieldLabel(),
                    Config::MIN_ABANDON_MINUTES,
                    $maxMinutes
                )
            );
        }

        return $this;
    }

    /**
     * The send limit saved together with this field, or the one already stored for the scope.
     *
     * @return int
     */
    private function getSendLimitMinutes(): int
    {
        $minutes = $this->getFieldsetDataValue('send_limit_minutes');
        if (!is_numeric($minutes)) {
            $minutes = $this->_config->getValue(
                Config::PATH_SEND_LIMIT_MINUTES,
                $this->getScope() ?: ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                $this->getScopeCode()
            );
        }

        // Read the way Config reads it: an invalid or negative stored value counts as the default.
        if (!is_numeric($minutes) || (int) $minutes < Config::NO_SEND_LIMIT) {
            return Config::DEFAULT_SEND_LIMIT_MINUTES;
        }

        return min(Config::MAX_SEND_LIMIT_MINUTES, (int) $minutes);
    }
}
