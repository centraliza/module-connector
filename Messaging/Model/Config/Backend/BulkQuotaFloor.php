<?php

declare(strict_types=1);

namespace Centraliza\Connector\Messaging\Model\Config\Backend;

use Centraliza\Connector\Messaging\Model\Api\QuotaReservation;

/**
 * Requests kept for the messages: over the abandoned-cart floor (20) and under the request limit, so the
 * connection events always stop first and still send.
 */
class BulkQuotaFloor extends IntegerRangeValue
{
    /**
     * @return int
     */
    protected function getMinimum(): int
    {
        return QuotaReservation::MIN_BULK_FLOOR;
    }

    /**
     * One under the request limit saved with it, or already saved; none while the limit is off.
     *
     * @return int|null
     */
    protected function getMaximum(): ?int
    {
        $fieldset = $this->getData('fieldset_data');
        $limit = is_array($fieldset) && array_key_exists('request_limit', $fieldset)
            ? $fieldset['request_limit']
            : $this->_config->getValue(QuotaReservation::PATH_REQUEST_LIMIT);
        $limit = is_scalar($limit) && trim((string) $limit) !== '' ? (int) $limit : QuotaReservation::DEFAULT_LIMIT;

        return $limit > 0 ? $limit - 1 : null;
    }
}
