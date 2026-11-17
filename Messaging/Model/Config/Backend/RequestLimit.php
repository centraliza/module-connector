<?php

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Config\Backend;

use Centraliza\Connector\Messaging\Model\Api\QuotaReservation;
use Magento\Framework\Exception\LocalizedException;

/**
 * Requests per minute per API key: 0 (no local limit) or enough to keep the floors of the messages under it.
 */
class RequestLimit extends IntegerRangeValue
{
    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave(): self
    {
        parent::beforeSave();

        $limit = (int) $this->getValue();
        if ($limit > 0 && $limit < QuotaReservation::MIN_LIMIT) {
            throw new LocalizedException(__(
                '%1 must be 0, for no limit, or at least %2.',
                $this->getFieldLabel(),
                QuotaReservation::MIN_LIMIT
            ));
        }

        return $this;
    }

    /**
     * @return int
     */
    protected function getMinimum(): int
    {
        return 0;
    }

    /**
     * @return int|null
     */
    protected function getMaximum(): ?int
    {
        return null;
    }
}
